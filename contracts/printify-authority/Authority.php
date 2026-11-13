<?php
declare(strict_types=1);
namespace DigiForge\IndependentAcceptance;

require_once __DIR__.'/../../includes/POD/PrintifyAcceptanceContract.php';
use DigiForge\POD\PrintifyAcceptanceContract as Contract;

/** OFFLINE REFERENCE ONLY. No deployed backend, HTTP listener or credential implementation. */
interface AtomicState { public function transaction(callable $work):mixed; }
/** Implementations must own custody, pin HTTPS api.printify.com, deny redirects, and never retry. */
interface ProviderPort {
 public function credentialAvailable(string $binding):bool;
 public function get(string $endpoint,int $timeout,int $maximumBytes):array;
}
interface EvidenceSigner { public function sign(string $bytes):string; }

final class Protocol {
 public const CONTRACT_SHA256='d04cea4a3c9133b507a369577da6e82e529e2d1ad87b4cc03eea3389aaea542e';
 public static function canonical(mixed $value,int $depth=0):string {
  if($depth>12)throw new \RuntimeException('Bound exceeded');
  if(is_array($value)) {if(!array_is_list($value))ksort($value,SORT_STRING);$parts=[];foreach($value as $k=>$v)$parts[$k]=json_decode(self::canonical($v,$depth+1),true,16,JSON_THROW_ON_ERROR);$value=$parts;}
  elseif(!is_int($value)&&!is_bool($value)&&$value!==null&&!is_string($value))throw new \RuntimeException('Invalid type');
  if(is_string($value)&&strlen($value)>4096)throw new \RuntimeException('Bound exceeded');
  $out=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(strlen($out)>16384)throw new \RuntimeException('Bound exceeded');return $out;
 }
 public static function keys(array $v,array $keys):void { $a=array_keys($v);sort($a);sort($keys);if($a!==$keys)throw new \RuntimeException('Schema mismatch'); }
 public static function verified(array $envelope,string $id,string $public):array {
  self::keys($envelope,['key_id','manifest','signature']);
  if($envelope['key_id']!==$id||!is_array($envelope['manifest'])||!is_string($envelope['signature'])||strlen($public)!==32)throw new \RuntimeException('Authority invalid');
  $sig=base64_decode($envelope['signature'],true);if($sig===false||strlen($sig)!==64||!sodium_crypto_sign_verify_detached($sig,self::canonical(['key_id'=>$id,'manifest'=>$envelope['manifest']]),$public))throw new \RuntimeException('Authority invalid');return $envelope['manifest'];
 }
}

final class Authority {
 private \Closure $clock;
 public function __construct(private array $config,private AtomicState $store,private ProviderPort $provider,private EvidenceSigner $signer,callable $clock){$this->clock=\Closure::fromCallable($clock);}
 private function now():int {$n=($this->clock)();if(!is_int($n)||$n<1)throw new \RuntimeException('Clock unavailable');return $n;}
 private function signed(array $receipt):array {$sig=$this->signer->sign(Protocol::canonical($receipt));$decoded=base64_decode($sig,true);if($decoded===false||strlen($decoded)!==64||!sodium_crypto_sign_verify_detached($decoded,Protocol::canonical($receipt),$this->config['receipt_public_key']))throw new \RuntimeException('Evidence unavailable');return ['receipt'=>$receipt,'signature'=>$sig,'receipt_committed'=>true];}
 private function audit(array &$s,string $event,string $correlation):void {$entry=['event'=>$event,'correlation'=>$correlation,'generation'=>$s['generation'],'time'=>$this->now(),'previous'=>empty($s['audit'])?str_repeat('0',64):$s['audit'][array_key_last($s['audit'])]['hash']];$signed=$this->signed($entry);$s['audit'][]=$signed+['hash'=>hash('sha256',Protocol::canonical($signed))];}
 private function state(array $s):void {
  foreach(['claims','nonces','stop_nonces','audit','receipts','times','sessions','catalog_grants'] as $k)if(!is_array($s[$k]??null))throw new \RuntimeException('State malformed');
  if(!is_int($s['generation']??null)||$s['generation']<1||!is_bool($s['stopped']??null)||!is_int($s['pause_until']??null)||$s['pause_until']<0||count($s['times'])>3||count($s['audit'])>=2048)throw new \RuntimeException('State malformed');
  foreach($s['claims'] as $claim)if(!is_array($claim)||!in_array($claim['state']??null,['RESERVED','ADMITTED','TERMINAL'],true))throw new \RuntimeException('Claim malformed');
  foreach($s['times'] as $time)if(!is_int($time)||$time<1||$time>$this->now())throw new \RuntimeException('Clock or budget malformed');
  $previous=str_repeat('0',64);
  foreach($s['audit'] as $row){if(!is_array($row)||!is_array($row['receipt']??null)||($row['receipt']['previous']??null)!==$previous||!is_string($row['signature']??null))throw new \RuntimeException('Audit malformed');$sig=base64_decode($row['signature'],true);$record=['receipt'=>$row['receipt'],'signature'=>$row['signature'],'receipt_committed'=>true];if($sig===false||strlen($sig)!==64||!sodium_crypto_sign_verify_detached($sig,Protocol::canonical($row['receipt']),$this->config['receipt_public_key'])||($row['hash']??null)!==hash('sha256',Protocol::canonical($record)))throw new \RuntimeException('Audit malformed');$previous=$row['hash'];}
 }
 private function common(array $m,string $protocol):void {
  if(($this->config['exclusive_credential_custody']??false)!==true||($this->config['independent_recovery_epoch']??false)!==true||($this->config['qualified']??false)!==true)throw new \RuntimeException('Not qualified');
  foreach(['authority','epoch'] as $k)if(($m[$k]??null)!==$this->config[$k])throw new \RuntimeException('Scope invalid');
  $n=$this->now();if(($m['protocol']??null)!==$protocol||($m['version']??null)!==1||($m['actor']??null)!==1||!is_int($m['issued_at']??null)||!is_int($m['expires_at']??null)||$m['issued_at']>$n||$m['expires_at']<=$n||$m['expires_at']-$m['issued_at']>($protocol==='printify-independent-stop-v1'?60:300)||$m['expires_at']<=$m['issued_at'])throw new \RuntimeException('Authority expired');
  foreach(['nonce'=>32,'owner_proof'=>64,'review'=>64] as $k=>$length)if(!is_string($m[$k]??null)||preg_match('/^[a-f0-9]{'.$length.'}$/D',$m[$k])!==1)throw new \RuntimeException('Malformed proof');
 }
 private function manifest(array $envelope):array {
  $m=Protocol::verified($envelope,$this->config['read_key_id'],$this->config['read_public_key']);
  Protocol::keys($m,['version','protocol','authority','epoch','generation','session','nonce','slot','actor','owner_proof','review','shop_key','internal_shop_id','integration_id','account','provider_shop_id','credential_binding','issued_at','expires_at','owner_verified_at','request','catalog_grant','contract_sha256']);
  $this->common($m,'printify-independent-get-v1');
  foreach(['account','credential_binding'] as $k)if($m[$k]!==$this->config[$k])throw new \RuntimeException('Scope invalid');
  if($m['shop_key']!=='personalized_pod'||$m['internal_shop_id']!==2||$m['integration_id']!==3||$m['provider_shop_id']!=='28930593'||$m['contract_sha256']!==Protocol::CONTRACT_SHA256||!hash_equals(Protocol::CONTRACT_SHA256,hash_file('sha256',__DIR__.'/../../includes/POD/PrintifyAcceptanceContract.php'))||!is_int($m['generation'])||!is_int($m['slot'])||$m['slot']<0||$m['slot']>=10||!is_string($m['session'])||preg_match('/^[a-f0-9]{32}$/D',$m['session'])!==1||!is_int($m['owner_verified_at'])||$m['owner_verified_at']>$this->now()||$m['owner_verified_at']<$this->now()-600||!is_array($m['request']))throw new \RuntimeException('Scope invalid');
  Protocol::keys($m['request'],['operation','method','endpoint','body']);Contract::inspect($m['request'],'28930593');return $m;
 }
 private function current(array $s,array $m):void {
  $this->state($s);
  if(($s['epoch']??null)!==$this->config['epoch']||($s['generation']??null)!==$m['generation']||($s['stopped']??true)!==false||($s['owner_proof']??null)!==$m['owner_proof']||($s['review']??null)!==$m['review']||$this->now()>=$m['expires_at'])throw new \RuntimeException('Authority unavailable');
  $session=$s['sessions'][$m['session']]??null;
  if(!is_array($session)||($session['revoked']??true)!==false||!is_int($session['expires_at']??null)||$session['expires_at']<=$this->now()||$session['expires_at']>$m['issued_at']+300||($session['owner_proof']??null)!==$m['owner_proof']||($session['review']??null)!==$m['review']||($session['slots'][$m['slot']]??null)!==hash('sha256',Protocol::canonical($m['request'])))throw new \RuntimeException('Session invalid');
  if(str_contains($m['request']['endpoint'],'/catalog/')) {
   $grant=is_string($m['catalog_grant'])?($s['catalog_grants'][$m['catalog_grant']]??null):null;
   if(!is_array($grant)||($grant['revoked']??true)!==false||!is_int($grant['expires_at']??null)||$grant['expires_at']<=$this->now()||$grant['expires_at']>$session['expires_at']||($grant['session']??null)!==$m['session']||($grant['generation']??null)!==$m['generation']||($grant['account']??null)!==$m['account']||($grant['credential_binding']??null)!==$m['credential_binding']||($grant['provider_shop_id']??null)!==$m['provider_shop_id']||!is_array($grant['slots']??null)||count($grant['slots'])>10||($grant['slots'][$m['slot']]??null)!==hash('sha256',Protocol::canonical($m['request'])))throw new \RuntimeException('Catalog grant missing');
  }
 }
 public function dispatch(array $envelope):array {
  $sent=false;
  try {
   $m=$this->manifest($envelope);if(!$this->provider->credentialAvailable($m['credential_binding']))throw new \RuntimeException('Custody unavailable');
   $id=hash('sha256',$m['session'].':'.$m['slot']);
   $this->store->transaction(function(array &$s)use($m,$id):void {
    $this->current($s,$m);$n=$this->now();$s['times']=array_values(array_filter($s['times'],static fn($v)=>is_int($v)&&$v>$n-60));
    if(isset($s['claims'][$id])||isset($s['nonces'][$m['nonce']])||count($s['times'])>=3||($s['pause_until']??0)>$n)throw new \RuntimeException('Budget denied');
    $s['claims'][$id]=['state'=>'RESERVED'];$s['nonces'][$m['nonce']]=true;$s['times'][]=$n;$this->audit($s,'RESERVED',$id);
   });
   $this->store->transaction(function(array &$s)use($m,$id):void {$this->current($s,$m);if(($s['claims'][$id]['state']??null)!=='RESERVED')throw new \RuntimeException('Claim invalid');if(!$this->provider->credentialAvailable($m['credential_binding']))throw new \RuntimeException('Custody unavailable');$s['claims'][$id]['state']='ADMITTED';$this->audit($s,'ADMITTED',$id);});
   // ADMITTED is the linearization boundary. A stop after this point reports DRAIN_PENDING.
   $sent=true;$status='UNKNOWN';$http=null;$observations=['values_disclosed'=>false];$retryAfter=0;
   try {$r=$this->provider->get($m['request']['endpoint'],20,1048576);$http=is_int($r['status']??null)?$r['status']:null;
    if($http===200&&is_string($r['body']??null)&&strlen($r['body'])<=1048576){$data=json_decode($r['body'],true,32,JSON_THROW_ON_ERROR);if(!is_array($data))throw new \RuntimeException('Invalid JSON container');$status='OBSERVED';$observations=Contract::observations($data);}
    elseif($http!==null&&$http>=300&&$http<=599)$status='REJECTED';
    if($http===429&&is_int($r['retry_after']??null))$retryAfter=max(1,min(3600,$r['retry_after']));
   }catch(\Throwable){$status='UNKNOWN';}
   return $this->store->transaction(function(array &$s)use($m,$id,$status,$http,$observations,$retryAfter):array {
    try{$this->current($s,$m);}catch(\Throwable){$status='UNKNOWN';$observations=['values_disclosed'=>false];}
    if(($s['epoch']??null)!==$this->config['epoch']||($s['claims'][$id]['state']??null)!=='ADMITTED')throw new \RuntimeException('State unavailable');
    $receipt=['status'=>$status,'operation'=>$m['request']['operation'],'api_version'=>Contract::inspect($m['request'],'28930593')['api_version'],'endpoint_template'=>Contract::inspect($m['request'],'28930593')['endpoint_template'],'shop'=>2,'integration'=>3,'provider_shop'=>'28930593','account_sha256'=>hash('sha256',$m['account']),'generation'=>$m['generation'],'correlation'=>$id,'timestamp'=>$this->now(),'http_status'=>$http,'observations'=>$observations,'compatibility'=>'UNVERIFIED'];
    $signed=$this->signed($receipt);$s['claims'][$id]['state']='TERMINAL';$s['receipts'][$id]=$signed;if($retryAfter>0)$s['pause_until']=$this->now()+$retryAfter;$this->audit($s,'TERMINAL',$id);return $signed;
   });
  }catch(\Throwable){return ['status'=>$sent?'UNKNOWN':'DENIED','receipt_committed'=>false,'retry_permitted'=>false];}
 }
 public function emergency(array $envelope):array {
  try {$m=Protocol::verified($envelope,$this->config['stop_key_id'],$this->config['stop_public_key']);Protocol::keys($m,['version','protocol','authority','epoch','actor','nonce','owner_proof','review','issued_at','expires_at']);$this->common($m,'printify-independent-stop-v1');
   return $this->store->transaction(function(array &$s)use($m):array {
    $this->state($s);
    $this->common($m,'printify-independent-stop-v1');
    if(($s['epoch']??null)!==$m['epoch']||($s['owner_proof']??null)!==$m['owner_proof']||($s['review']??null)!==$m['review']||isset($s['stop_nonces'][$m['nonce']])||!is_int($s['generation'])||$s['generation']>=PHP_INT_MAX)throw new \RuntimeException('Emergency admission failed');
    $s['generation']++;$s['stopped']=true;$s['stop_nonces'][$m['nonce']]=true;$pending=false;foreach($s['claims'] as $claim)if($claim['state']==='ADMITTED')$pending=true;
    $signed=$this->signed(['status'=>$pending?'DRAIN_PENDING':'DRAIN_ACKNOWLEDGED','generation'=>$s['generation'],'epoch'=>$s['epoch'],'timestamp'=>$this->now()]);$this->audit($s,'EMERGENCY',hash('sha256',$m['nonce']));return $signed+['durable_revocation_recorded'=>true];
   });
  }catch(\Throwable){return ['status'=>'UNKNOWN','durable_revocation_recorded'=>false,'retry_permitted'=>false];}
 }
}
