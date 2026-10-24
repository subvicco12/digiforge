<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;
use DigiForge\Integrations\CredentialVault;

/** Internal server-side OAuth lifecycle. No route, UI activation or live call on construction. */
final class DriveGoogleOAuth
{
    public function __construct(private DriveGrantStore $store,private DriveGoogleGate $gate,private DriveGoogleHttp $http,
        private string $clientId,private string $callback,#[\SensitiveParameter] private string $clientSecretCiphertext)
    {
        $u=parse_url($callback);
        if(!is_array($u)||($u['scheme']??'')!=='https'||empty($u['host'])||empty($u['path'])
            ||array_diff(array_keys($u),['scheme','host','path'])!==[]
            ||preg_match('/^[A-Za-z0-9.-]{1,200}\.apps\.googleusercontent\.com$/D',$clientId)!==1
            ||strlen($clientSecretCiphertext)>16384||preg_match('/^(?:sodium|openssl):v1:[A-Za-z0-9+\/]+=*$/D',$clientSecretCiphertext)!==1)throw new \InvalidArgumentException('Google configuration unavailable.');
    }
    public static function key(string $kind,string $id):string{return 'digiforge_google_'.$kind.'_'.hash('sha256',$id);}
    public static function digest(array $value):string{return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR));}
    public static function binding(array $plan):array
    {
        $keys=['id','installation_sha256','environment','shop_key','folder_id','actor_id','product_id','product_version_id','revision_id','source_sha256'];
        $b=[];foreach($keys as $k){if(!array_key_exists($k,$plan))throw new \InvalidArgumentException('Google binding unavailable.');$b[$k]=$plan[$k];}
        $probe=$b+['issuer'=>'digiforge_google_v1','connection_id'=>'probe','nonce'=>str_repeat('0',64),'issued_at'=>1,'expires_at'=>2,'remaining_attempts'=>1,'stages'=>['folder']];
        if(!DriveExecutionGrant::validate($probe,$b,'folder',1,false,true))throw new \InvalidArgumentException('Google binding unavailable.');return $b;
    }
    public function begin(array $plan,#[\SensitiveParameter] string $session,int $now):?array
    {
        try{
            $b=self::binding($plan);if($now!==$this->gate->now()||$now<1||$session===''||strlen($session)>4096||!$this->gate->allows($b))return null;
            $state=bin2hex(random_bytes(32));$verifier=bin2hex(random_bytes(32));$id=bin2hex(random_bytes(24));$key=self::key('oauth',$state);
            $secret=['verifier'=>$verifier,'connection_id'=>$id,'callback'=>$this->callback,'client_id'=>$this->clientId];
            $record=['binding'=>$b,'session_sha256'=>hash('sha256',$session),'expires_at'=>$now+600,'state'=>'PENDING',
                'ciphertext'=>CredentialVault::encryptExisting(json_encode($secret,JSON_THROW_ON_ERROR),'google:'.$key)];
            if(!$this->gate->allows($b)||!$this->store->compareAndSwap($key,[],$record))return null;
            return ['authorization_url'=>'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
                'client_id'=>$this->clientId,'redirect_uri'=>$this->callback,'response_type'=>'code','scope'=>DriveOAuthResponse::SCOPE,
                'access_type'=>'offline','prompt'=>'consent','include_granted_scopes'=>'false','state'=>$state,
                'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256'],'','&',PHP_QUERY_RFC3986),
                'expires_at'=>$now+600,'external_execution_authorized'=>false];
        }catch(\Throwable){return null;}
    }
    public function complete(array $plan,#[\SensitiveParameter] string $session,#[\SensitiveParameter] string $state,
        #[\SensitiveParameter] string $code,string $callback,int $now):?array
    {
        try{
            $b=self::binding($plan);
            if($now!==$this->gate->now()||!self::hex($state)||$callback!==$this->callback||$code===''||strlen($code)>4096||preg_match('/[\x00-\x20\x7f]/',$code)||!$this->gate->allows($b))return null;
            $key=self::key('oauth',$state);$row=$this->store->read($key);
            if(!is_array($row)||($row['binding']??null)!==$b||($row['state']??null)!=='PENDING'
                ||!is_int($row['expires_at']??null)||$now<($row['expires_at']-600)||$now>=$row['expires_at']
                ||!hash_equals($row['session_sha256']??'',hash('sha256',$session)))return null;
            if(!$this->gate->allows($b))return null;
            $secret=json_decode(CredentialVault::decryptExisting($row['ciphertext'],'google:'.$key),true,16,JSON_THROW_ON_ERROR);
            if(($secret['callback']??null)!==$callback||($secret['client_id']??null)!==$this->clientId||!self::hex($secret['verifier']??null))return null;
            // Burn before the first external attempt. Crash/timeout can never replay a code.
            $next=$row;$next['state']='CONSUMED';$next['ciphertext']='';if(!$this->store->compareAndSwap($key,$row,$next))return null;
            $token=$this->form($b,'token',['code'=>$code,'client_id'=>$this->clientId,'client_secret'=>$this->clientSecret($b),
                'redirect_uri'=>$callback,'grant_type'=>'authorization_code','code_verifier'=>$secret['verifier']]);
            $tokens=$this->tokens($token,[],$now);$account=$this->account($b,$tokens['access_token']);
            if(!$this->gate->allows($b))return null;
            $id=$secret['connection_id'];$connectionKey=self::key('connection',$id);
            $connection=$this->seal($b,$id,$account,$tokens,$now);
            if(!$this->store->compareAndSwap($connectionKey,[],$connection))return null;
            return ['connection_id'=>$id,'account_id'=>$account,'generation'=>$connection['generation'],'state'=>'CONNECTED','external_execution_authorized'=>false];
        }catch(\Throwable){return null;}
    }
    /** Metadata only. Never decrypts or returns credentials.
     * @phpstan-impure
     */
    public function connection(array $plan,string $id,int $now):?array
    {
        try{
            $now=$this->gate->now();$b=self::binding($plan);if(!DriveTransferRequest::validId($id)||!$this->gate->allows($b))return null;
            $row=$this->store->read(self::key('connection',$id));
            if(!is_array($row)||($row['binding']??null)!==$b||($row['id']??null)!==$id||($row['state']??null)!=='ACTIVE'
                ||!DriveTransferRequest::validId($row['account_id']??null)||!self::hex($row['generation']??null)
                ||($row['client_sha256']??null)!==hash('sha256',$this->clientId."\0".$this->callback)||($row['scope']??null)!==DriveOAuthResponse::SCOPE||!is_int($row['expires_at']??null)
                ||!is_int($row['created_at']??null)||$now<$row['created_at']||$now>=$row['expires_at']
                ||!is_string($row['ciphertext']??null)||$row['ciphertext']==='')return null;
            unset($row['ciphertext']);return $row;
        }catch(\Throwable){return null;}
    }
    /** Transient server-only credential, never a controller response or audit field. */
    public function access(array $plan,string $id,string $generation,int $now):?string
    {
        try{
            $now=$this->gate->now();$current=$this->connection($plan,$id,$now);if($current===null||$current['generation']!==$generation||$current['expires_at']<=$now+30||!$this->gate->allows($current['binding']))return null;
            $row=$this->store->read(self::key('connection',$id));if(!is_array($row)||array_diff_key($row,['ciphertext'=>true])!==$current)return null;
            $plain=$this->unseal($row);if(!$this->gate->allows($current['binding'])||$this->store->read(self::key('connection',$id))!==$row)return null;
            return $plain['tokens']['access_token'];
        }catch(\Throwable){return null;}
    }
    public function refresh(array $plan,string $id,int $now):bool
    {
        try{
            $b=self::binding($plan);if($now!==$this->gate->now()||!$this->gate->allows($b))return false;
            $key=self::key('connection',$id);$row=$this->store->read($key);
            if(!is_array($row)||($row['binding']??null)!==$b||($row['state']??null)!=='ACTIVE'||($row['client_sha256']??null)!==hash('sha256',$this->clientId."\0".$this->callback)||$now<($row['created_at']??PHP_INT_MAX))return false;
            if(!$this->gate->allows($b))return false;$plain=$this->unseal($row);$old=$plain['tokens'];
            if(!is_string($old['refresh_token']??null)||$old['refresh_token']===''||($old['refresh_expires_at']!==null&&$now>=$old['refresh_expires_at']))return false;
            $claimed=$row;$claimed['state']='REFRESHING';if(!$this->store->compareAndSwap($key,$row,$claimed))return false;
            try{
                $response=$this->form($b,'token',['client_id'=>$this->clientId,'client_secret'=>$this->clientSecret($b),'refresh_token'=>$old['refresh_token'],'grant_type'=>'refresh_token']);
                $tokens=$this->tokens($response,$old,$now);$account=$this->account($b,$tokens['access_token']);
                if($account!==$row['account_id']||!$this->gate->allows($b))throw new \RuntimeException('Google account unavailable.');
                // Rotating generation invalidates all previously issued grants.
                return $this->store->compareAndSwap($key,$claimed,$this->seal($b,$id,$account,$tokens,$now));
            }catch(\Throwable){$failed=$claimed;$failed['state']='UNKNOWN';$this->store->compareAndSwap($key,$claimed,$failed);return false;}
        }catch(\Throwable){return false;}
    }
    /** Local revocation works under STOP ALL; provider revocation only when separately allowed. */
    public function revoke(array $plan,string $id,int $now):bool
    {
        try{
            $b=self::binding($plan);if(!$this->gate->owns($b))return false;$key=self::key('connection',$id);$row=$this->store->read($key);
            if(!is_array($row)||($row['binding']??null)!==$b)return false;if(($row['state']??null)==='REVOKED')return true;
            $next=$row;$next['state']='REVOKED';$next['ciphertext']='';$next['revoked_at']=$now;$next['provider_revocation']='UNKNOWN';
            if(!$this->store->compareAndSwap($key,$row,$next))return false;
            if($this->gate->allows($b)){
                try{$plain=$this->unseal($row);$r=$this->formRaw($b,'revoke',['token'=>$plain['tokens']['refresh_token']??$plain['tokens']['access_token']]);
                    if(($r['status']??0)===200&&$this->gate->allows($b)){$done=$next;$done['provider_revocation']='OBSERVED';$this->store->compareAndSwap($key,$next,$done);}
                }catch(\Throwable){/* Local revocation remains durable; remote outcome unknown. */}
            }
            return true;
        }catch(\Throwable){return false;}
    }
    private function seal(array $binding,string $id,string $account,array $tokens,int $now):array
    {
        $row=['binding'=>$binding,'id'=>$id,'account_id'=>$account,'generation'=>bin2hex(random_bytes(32)),
            'client_sha256'=>hash('sha256',$this->clientId."\0".$this->callback),'scope'=>DriveOAuthResponse::SCOPE,'state'=>'ACTIVE','created_at'=>$now,'expires_at'=>$tokens['expires_at']];
        $secret=['metadata'=>$row,'tokens'=>$tokens];$row['ciphertext']=CredentialVault::encryptExisting(json_encode($secret,JSON_THROW_ON_ERROR),'google:'.self::key('connection',$id));return $row;
    }
    private function unseal(array $row):array
    {
        $plain=json_decode(CredentialVault::decryptExisting($row['ciphertext'],'google:'.self::key('connection',$row['id'])),true,16,JSON_THROW_ON_ERROR);
        $metadata=$row;unset($metadata['ciphertext']);
        // During refresh/revocation only the durable state differs from the sealed ACTIVE record.
        $metadata['state']='ACTIVE';unset($metadata['revoked_at'],$metadata['provider_revocation']);
        if(($plain['metadata']??null)!==$metadata||!is_array($plain['tokens']??null))throw new \RuntimeException('Google custody unavailable.');return $plain;
    }
    private function tokens(array $response,array $previous,int $now):array
    {
        if(!isset($response['scope'])&&$previous!==[])$response['scope']=DriveOAuthResponse::SCOPE;
        $observation=DriveOAuthResponse::describe($response,$now,$now,false);
        $refresh=$response['refresh_token']??($previous['refresh_token']??null);
        if(!is_string($refresh)||preg_match('/^[A-Za-z0-9._~+\/-]{1,4096}=*$/D',$refresh)!==1)throw new \RuntimeException('Google refresh custody unavailable.');
        $expiry=$previous['refresh_expires_at']??null;
        if(isset($response['refresh_token_expires_in'])){if(!is_int($response['refresh_token_expires_in'])||$response['refresh_token_expires_in']<1||$response['refresh_token_expires_in']>315360000)throw new \RuntimeException('Google expiry unavailable.');$expiry=$now+$response['refresh_token_expires_in'];}
        return ['access_token'=>$response['access_token'],'refresh_token'=>$refresh,'expires_at'=>$observation['expires_at'],'refresh_expires_at'=>$expiry];
    }
    private function account(array $binding,#[\SensitiveParameter] string $access):string
    {
        if(!$this->gate->allows($binding))throw new \RuntimeException('Google execution unavailable.');
        $args=self::args('GET');$args['headers']['Authorization']='Bearer '.$access;
        $r=$this->http->exchange($binding,'https://www.googleapis.com/drive/v3/about?fields=user%28permissionId%29',$args);
        $data=self::json($r);$id=$data['user']['permissionId']??null;
        if(!$this->gate->allows($binding)||!DriveTransferRequest::validId($id))throw new \RuntimeException('Google identity unavailable.');return $id;
    }
    public function __debugInfo():array{return ['state'=>'GOOGLE_OAUTH_INTERNAL','credentials'=>'REDACTED'];}
    private function clientSecret(array $binding):string
    {
        if(!$this->gate->allows($binding))throw new \RuntimeException('Google execution unavailable.');
        $value=CredentialVault::decryptExisting($this->clientSecretCiphertext,'google:oauth-client:'.hash('sha256',$this->clientId."\0".$this->callback));
        if($value===''||strlen($value)>4096||preg_match('/[\x00-\x20\x7f]/',$value))throw new \RuntimeException('Google client custody unavailable.');return $value;
    }
    private function form(array $binding,string $action,#[\SensitiveParameter] array $body):array{return self::json($this->formRaw($binding,$action,$body));}
    private function formRaw(array $binding,string $action,#[\SensitiveParameter] array $body):array
    {
        if(!$this->gate->allows($binding))throw new \RuntimeException('Google execution unavailable.');
        $args=self::args('POST');$args['headers']['Content-Type']='application/x-www-form-urlencoded';$args['body']=http_build_query($body,'','&',PHP_QUERY_RFC3986);
        $r=$this->http->exchange($binding,'https://oauth2.googleapis.com/'.$action,$args);
        if(!$this->gate->allows($binding))throw new \RuntimeException('Google execution unavailable.');return $r;
    }
    private static function args(string $method):array{return ['method'=>$method,'timeout'=>20,'redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>true,'limit_response_size'=>16385,'headers'=>['Accept'=>'application/json','Accept-Encoding'=>'identity']];}
    private static function json(#[\SensitiveParameter] array $r):array
    {
        $body=$r['body']??null;$headers=array_change_key_case($r['headers']??[],CASE_LOWER);
        if(($r['status']??0)!==200||!is_string($body)||strlen($body)>16384||!is_string($headers['content-type']??null)
            ||strtolower(trim(explode(';',$headers['content-type'])[0]))!=='application/json'
            ||isset($headers['content-encoding'])&&$headers['content-encoding']!=='identity')throw new \RuntimeException('Google response unavailable.');
        $v=json_decode($body,true,16,JSON_THROW_ON_ERROR);if(!is_array($v)||array_is_list($v)||isset($v['error']))throw new \RuntimeException('Google response unavailable.');return $v;
    }
    private static function hex(mixed $v):bool{return is_string($v)&&preg_match('/^[a-f0-9]{64}$/D',$v)===1;}
}
