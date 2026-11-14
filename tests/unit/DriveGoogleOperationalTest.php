<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForge\DigitalFactory\{DriveGoogleGate,DriveGoogleHttp,DriveGrantStore,DriveGoogleOAuth,DriveGoogleAuthority,DriveGoogleDispatcher,DriveTransferRequest,DriveGrantJournal};
require_once dirname(__DIR__,2).'/includes/DigitalFactory/DriveGrantStore.php';
require_once dirname(__DIR__,2).'/includes/DigitalFactory/DriveGoogleGate.php';
require_once dirname(__DIR__,2).'/includes/DigitalFactory/DriveGoogleHttp.php';

// Only the external boundary is synthetic; issuer, OAuth, encryption and journal are real.
final class OperationalMemoryStore implements DriveGrantStore {
    public array $rows=[];
    public bool $fail=false;
    public function read(string $key):?array{return $this->fail?null:($this->rows[$key]??[]);}
    public function compareAndSwap(string $key,array $expected,array $next):bool{if($this->fail||($this->rows[$key]??[])!==$expected)return false;$this->rows[$key]=$next;return true;}
}
final class OperationalGate implements DriveGoogleGate {
    public int $clock=100;
    public function now():int{return $this->clock;}
    public bool $allowed=true;
    public function owns(array $binding):bool{return true;}
    public function allows(array $binding):bool{return $this->allowed;}
}
final class OperationalHttp implements DriveGoogleHttp {
    public array $requests=[];
    public array $responses=[];
    public ?Closure $during=null;
    public function exchange(array $binding,string $url,array $args):array {
        $this->requests[]=[$url,$args];if($this->during){$f=$this->during;$this->during=null;$f();}
        $r=array_shift($this->responses);if($r instanceof Throwable)throw $r;return $r??['status'=>0,'headers'=>[],'body'=>''];
    }
}
final class DriveGoogleOperationalTest extends TestCase {
    private OperationalMemoryStore $store;
    private OperationalGate $gate;
    private OperationalHttp $http;
    private DriveGoogleOAuth $oauth;
    private array $plan;
    protected function setUp():void {
        
        foreach(['DriveTransferRequest','DriveExecutionGrant','DriveGrantJournal','DriveHttpsProtocol','DriveOAuthResponse','DriveGoogleOAuth','DriveGoogleAuthority','DriveGoogleDispatcher'] as $name)require_once dirname(__DIR__,2).'/includes/DigitalFactory/'.$name.'.php';
        require_once dirname(__DIR__,2).'/includes/Integrations/CredentialVault.php';
        if(!defined('DIGIFORGE_CREDENTIAL_KEY'))define('DIGIFORGE_CREDENTIAL_KEY',str_repeat('synthetic-google-test-key-',2));
        $this->store=new OperationalMemoryStore();$this->gate=new OperationalGate();$this->http=new OperationalHttp();
        $this->plan=['id'=>str_repeat('a',64),'installation_sha256'=>str_repeat('b',64),'environment'=>'local','shop_key'=>'digital','folder_id'=>DriveTransferRequest::FOLDER,'actor_id'=>7,'product_id'=>9,'product_version_id'=>5,'revision_id'=>12,'source_sha256'=>hash('sha256','original-docx')];
        $client=\DigiForge\Integrations\CredentialVault::encryptExisting('synthetic-client-secret','google:oauth-client:'.hash('sha256',"synthetic.apps.googleusercontent.com\0https://example.test/google/callback"));
        $this->oauth=new DriveGoogleOAuth($this->store,$this->gate,$this->http,'synthetic.apps.googleusercontent.com','https://example.test/google/callback',$client);
    }
    private function json(array $body):array{return ['status'=>200,'headers'=>['content-type'=>'application/json'],'body'=>json_encode($body,JSON_THROW_ON_ERROR)];}
    private function token(string $value='synthetic-access-only'):array{return ['access_token'=>$value,'refresh_token'=>'synthetic-refresh-only','token_type'=>'Bearer','scope'=>'https://www.googleapis.com/auth/drive.file','expires_in'=>3600];}
    private function connect():string {
        $start=$this->oauth->begin($this->plan,'browser-session',($this->gate->clock=100));
        self::assertNotNull($start);parse_str(parse_url($start['authorization_url'],PHP_URL_QUERY),$q);
        self::assertSame('S256',$q['code_challenge_method']);self::assertSame('https://www.googleapis.com/auth/drive.file',$q['scope']);
        $this->http->responses=[$this->json($this->token()),$this->json(['user'=>['permissionId'=>'synthetic-account-7']])];
        $result=$this->oauth->complete($this->plan,'browser-session',$q['state'],'synthetic-code','https://example.test/google/callback',($this->gate->clock=101));
        self::assertNotNull($result);self::assertArrayNotHasKey('access_token',$result);
        parse_str($this->http->requests[array_key_last($this->http->requests)-1][1]['body'],$sent);
        self::assertSame($q['code_challenge'],rtrim(strtr(base64_encode(hash('sha256',$sent['code_verifier'],true)),'+/','-_'),'='));
        return $result['connection_id'];
    }
    public function testStatePkceAccountBindingAndEncryptedCustody():void {
        $id=$this->connect();$serialized=json_encode($this->store->rows);
        self::assertStringNotContainsString('synthetic-access-only',$serialized);self::assertStringNotContainsString('synthetic-refresh-only',$serialized);self::assertStringNotContainsString('synthetic-client-secret',$serialized);
        $record=$this->oauth->connection($this->plan,$id,($this->gate->clock=102));self::assertSame('synthetic-account-7',$record['account_id']);
        $foreign=$this->plan;$foreign['actor_id']=8;self::assertNull($this->oauth->connection($foreign,$id,($this->gate->clock=102)));
        self::assertNull($this->oauth->complete($this->plan,'browser-session','bad','code','https://example.test/google/callback',($this->gate->clock=102)));
    }
    public function testOAuthReplayWrongSessionCallbackExpiryAndSafetyNeverSend():void {
        foreach(['session','callback','expired','locked'] as $case){
            $start=$this->oauth->begin($this->plan,'browser-session',($this->gate->clock=100));parse_str(parse_url($start['authorization_url'],PHP_URL_QUERY),$q);
            $before=count($this->http->requests);if($case==='locked')$this->gate->allowed=false;
            self::assertNull($this->oauth->complete($this->plan,$case==='session'?'other':'browser-session',$q['state'],'code',$case==='callback'?'https://evil.test/cb':'https://example.test/google/callback',$case==='expired'?701:101));
            self::assertCount($before,$this->http->requests);$this->gate->allowed=true;
        }
        $start=$this->oauth->begin($this->plan,'browser-session',($this->gate->clock=100));parse_str(parse_url($start['authorization_url'],PHP_URL_QUERY),$q);
        $this->http->responses=[new RuntimeException('sensitive provider failure')];self::assertNull($this->oauth->complete($this->plan,'browser-session',$q['state'],'code','https://example.test/google/callback',($this->gate->clock=101)));
        $before=count($this->http->requests);self::assertNull($this->oauth->complete($this->plan,'browser-session',$q['state'],'code','https://example.test/google/callback',($this->gate->clock=102)));self::assertCount($before,$this->http->requests);
    }
    public function testTrustedIssuerCommitsExactAccountAndRejectsLocalAuthority():void {
        $id=$this->connect();$a=new DriveGoogleAuthority($this->store,$this->gate,$this->oauth);
        $g=$a->issue($this->plan,$id,['folder','original_upload','reconcile_original_upload'],2,($this->gate->clock=102),300);self::assertNotNull($g);self::assertSame('synthetic-account-7',$g['account_id']);
        self::assertNotNull((new DriveGrantJournal($this->store))->reserve($g,$this->plan,'folder',str_repeat('1',64),($this->gate->clock=103)));
        $this->gate->allowed=false;self::assertNull($a->issue($this->plan,$id,['folder'],1,($this->gate->clock=103),300));$this->gate->allowed=true;
        self::assertNull($a->issue($this->plan,$id,['delete'],1,($this->gate->clock=103),300));self::assertNull($a->issue($this->plan,$id,['folder'],1,($this->gate->clock=103),901));
        $foreign=$this->plan;$foreign['source_sha256']=str_repeat('f',64);self::assertNull($a->current($foreign,$g,'folder',($this->gate->clock=103)));
    }
    public function testDispatchUsesCanonicalWireAndDurableCreateIdentityAcrossGrants():void {
        $id=$this->connect();$a=new DriveGoogleAuthority($this->store,$this->gate,$this->oauth);$d=new DriveGoogleDispatcher($this->store,$this->gate,$this->oauth,$a,$this->http);
        $folder=$a->issue($this->plan,$id,['folder'],1,($this->gate->clock=102),300);
        $this->http->responses=[$this->json(['id'=>DriveTransferRequest::FOLDER,'mimeType'=>'application/vnd.google-apps.folder','trashed'=>false,'capabilities'=>['canAddChildren'=>true]])];
        self::assertSame('OBSERVED',$d->dispatch($this->plan,$folder,'folder',[],'original-docx',str_repeat('9',64),($this->gate->clock=102))['state']);
        $g=$a->issue($this->plan,$id,['original_upload','reconcile_original_upload'],3,($this->gate->clock=102),300);
        $this->http->responses=[new RuntimeException('timeout with token material')];
        $r=$d->dispatch($this->plan,$g,'original_upload',[], 'original-docx',str_repeat('1',64),($this->gate->clock=103));self::assertSame('UNKNOWN',$r['state']);
        $before=count($this->http->requests);$g2=$a->issue($this->plan,$id,['original_upload','reconcile_original_upload'],3,($this->gate->clock=104),300);
        self::assertSame('BLOCKED',$d->dispatch($this->plan,$g2,'original_upload',[],'original-docx',str_repeat('2',64),($this->gate->clock=105))['state']);self::assertCount($before,$this->http->requests);
        $this->http->responses=[$this->json(['files'=>[]])];$r=$d->dispatch($this->plan,$g2,'reconcile_original_upload',[],'original-docx',str_repeat('3',64),($this->gate->clock=106));self::assertSame('UNKNOWN',$r['state']);
        self::assertSame('GET',$this->http->requests[array_key_last($this->http->requests)][1]['method']);
    }
    public function testDispatchRechecksSafetyOwnershipSourceAndRevocation():void {
        $id=$this->connect();$a=new DriveGoogleAuthority($this->store,$this->gate,$this->oauth);$d=new DriveGoogleDispatcher($this->store,$this->gate,$this->oauth,$a,$this->http);$g=$a->issue($this->plan,$id,['folder'],4,($this->gate->clock=102),300);
        $before=count($this->http->requests);self::assertSame('BLOCKED',$d->dispatch($this->plan,$g,'folder',[],'tampered',str_repeat('1',64),($this->gate->clock=103))['state']);self::assertCount($before,$this->http->requests);
        $this->gate->allowed=false;self::assertSame('BLOCKED',$d->dispatch($this->plan,$g,'folder',[],'original-docx',str_repeat('2',64),($this->gate->clock=103))['state']);self::assertCount($before,$this->http->requests);$this->gate->allowed=true;
        $this->http->responses=[$this->json(['id'=>DriveTransferRequest::FOLDER])];$this->http->during=function(){$this->gate->allowed=false;};
        self::assertSame('UNKNOWN',$d->dispatch($this->plan,$g,'folder',[],'original-docx',str_repeat('3',64),($this->gate->clock=104))['state']);
    }
    public function testRefreshRotatesGenerationAndRevocationIsLocalFirst():void {
        $id=$this->connect();$old=$this->oauth->connection($this->plan,$id,($this->gate->clock=102));
        $this->http->responses=[$this->json($this->token('synthetic-rotated-access')),$this->json(['user'=>['permissionId'=>'synthetic-account-7']])];
        self::assertTrue($this->oauth->refresh($this->plan,$id,($this->gate->clock=103)));$new=$this->oauth->connection($this->plan,$id,($this->gate->clock=104));self::assertNotSame($old['generation'],$new['generation']);
        $this->gate->allowed=false;$before=count($this->http->requests);self::assertTrue($this->oauth->revoke($this->plan,$id,($this->gate->clock=105)));self::assertCount($before,$this->http->requests);self::assertNull($this->oauth->connection($this->plan,$id,($this->gate->clock=106)));
    }
    public function testInvalidTokenScopeAccountSwitchAndRefreshTimeoutStayBlocked():void {
        $id=$this->connect();$this->http->responses=[$this->json($this->token()),$this->json(['user'=>['permissionId'=>'foreign-account']])];self::assertFalse($this->oauth->refresh($this->plan,$id,($this->gate->clock=103)));self::assertNull($this->oauth->connection($this->plan,$id,($this->gate->clock=104)));
    }
    public function testForgedCompletedIdentityAndMissingOriginalReadbackNeverSend():void {
        $id=$this->connect();$a=new DriveGoogleAuthority($this->store,$this->gate,$this->oauth);$d=new DriveGoogleDispatcher($this->store,$this->gate,$this->oauth,$a,$this->http);
        $g=$a->issue($this->plan,$id,['original_readback','native_conversion'],4,($this->gate->clock=102),300);$before=count($this->http->requests);
        self::assertSame('BLOCKED',$d->dispatch($this->plan,$g,'original_readback',['original_upload'=>['id'=>'foreign-file']],'original-docx',str_repeat('4',64),($this->gate->clock=103))['state']);
        self::assertSame('BLOCKED',$d->dispatch($this->plan,$g,'native_conversion',[],'original-docx',str_repeat('5',64),($this->gate->clock=104))['state']);self::assertCount($before,$this->http->requests);
    }

    private function metadata(string $role,string $id,string $version='1'):array {
        return ['id'=>$id,'mimeType'=>$role==='original_upload'?DriveTransferRequest::DOCX:DriveTransferRequest::DOC,
            'parents'=>[DriveTransferRequest::FOLDER],'trashed'=>false,'version'=>$version,'capabilities'=>['canDownload'=>true],
            'appProperties'=>['digiforge_transfer'=>$this->plan['id'],'digiforge_role'=>$role,'source_sha256'=>$this->plan['source_sha256']]];
    }
    public function testWholeSyntheticSequencePreservesOriginalAndNativeVersion():void {
        $id=$this->connect();$a=new DriveGoogleAuthority($this->store,$this->gate,$this->oauth);$d=new DriveGoogleDispatcher($this->store,$this->gate,$this->oauth,$a,$this->http);
        $stages=['folder','original_upload','original_readback','native_conversion','native_readback','pdf_export','native_final'];
        $g=$a->issue($this->plan,$id,$stages,9,($this->gate->clock=102),300);$original=$this->metadata('original_upload','original-file');$native=$this->metadata('native_conversion','native-file');
        $this->http->responses=[$this->json(['id'=>DriveTransferRequest::FOLDER,'mimeType'=>'application/vnd.google-apps.folder','trashed'=>false,'capabilities'=>['canAddChildren'=>true]]),
            $this->json($original),['status'=>200,'headers'=>['content-type'=>DriveTransferRequest::DOCX],'body'=>'original-docx'],
            $this->json($native),$this->json($native),['status'=>200,'headers'=>['content-type'=>'application/pdf'],'body'=>'%PDF-synthetic-unreviewed'],$this->json($native)];
        foreach($stages as $i=>$stage){$r=$d->dispatch($this->plan,$g,$stage,[],'original-docx',hash('sha256','whole-'.$i),($this->gate->clock=103));self::assertSame('OBSERVED',$r['state'],$stage);self::assertSame('UNKNOWN',$r['provider_acceptance']);self::assertFalse($r['production_authorized']);}
        self::assertCount(9,$this->http->requests);
        $wire=$this->http->requests[3][1];self::assertStringContainsString('original-docx',$wire['body']);self::assertSame(0,$wire['redirection']);self::assertTrue($wire['sslverify']);
        $this->http->responses=[$this->json($this->metadata('native_conversion','native-file','2'))];
        self::assertSame('UNKNOWN',$d->dispatch($this->plan,$g,'native_final',[],'original-docx',str_repeat('f',64),($this->gate->clock=104))['state']);
    }
    public function testOAuthRejectsBroaderScopesAndAccountReadsAfterSafetyChanges():void {
        $start=$this->oauth->begin($this->plan,'browser-session',($this->gate->clock=100));parse_str(parse_url($start['authorization_url'],PHP_URL_QUERY),$q);
        $token=$this->token();$token['scope'].=' https://www.googleapis.com/auth/drive';$this->http->responses=[$this->json($token)];
        self::assertNull($this->oauth->complete($this->plan,'browser-session',$q['state'],'code','https://example.test/google/callback',($this->gate->clock=101)));self::assertCount(1,$this->http->requests);
        $start=$this->oauth->begin($this->plan,'browser-session',($this->gate->clock=102));parse_str(parse_url($start['authorization_url'],PHP_URL_QUERY),$q);
        $this->http->responses=[$this->json($this->token())];$this->http->during=function(){$this->gate->allowed=false;};
        self::assertNull($this->oauth->complete($this->plan,'browser-session',$q['state'],'code','https://example.test/google/callback',($this->gate->clock=103)));self::assertCount(2,$this->http->requests);
    }
    public function testTransportRejectsUnsafeOriginsOptionsRedirectsAndHeaderInjection():void {
        require_once dirname(__DIR__,2).'/includes/DigitalFactory/DriveWordPressGoogleHttp.php';
        $request=DriveTransferRequest::build('folder',$this->plan,[]);$wire=\DigiForge\DigitalFactory\DriveHttpsProtocol::encode($request,$this->plan,[],'original-docx');$args=$wire['args'];$args['headers']['Authorization']='Bearer synthetic-access-only';
        self::assertTrue(\DigiForge\DigitalFactory\DriveWordPressGoogleHttp::valid($wire['url'],$args));
        foreach(['https://evil.test/drive/v3/files/x','http://www.googleapis.com/drive/v3/files/x','https://www.googleapis.com.evil.test/drive/v3/files/x','https://www.googleapis.com:443/drive/v3/files/x','https://www.googleapis.com/drive/v3/files/x#fragment','https://www.googleapis.com/drive/v3/files/../x'] as $url)self::assertFalse(\DigiForge\DigitalFactory\DriveWordPressGoogleHttp::valid($url,$args));
        foreach(['redirection'=>1,'sslverify'=>false,'limit_response_size'=>10000002,'stream'=>true,'method'=>'DELETE','reject_unsafe_urls'=>false] as $key=>$value){$bad=$args;$bad[$key]=$value;self::assertFalse(\DigiForge\DigitalFactory\DriveWordPressGoogleHttp::valid($wire['url'],$bad));}
        $bad=$args;$bad['headers']['Authorization'].="\r\nInjected: true";self::assertFalse(\DigiForge\DigitalFactory\DriveWordPressGoogleHttp::valid($wire['url'],$bad));
        $bad=$args;$bad['headers']['Cookie']='session=forbidden';self::assertFalse(\DigiForge\DigitalFactory\DriveWordPressGoogleHttp::valid($wire['url'],$bad));
    }

    public function testRevokedGrantsCorruptCiphertextAndPersistenceFailuresDenyWithoutSending():void {
        $id=$this->connect();$a=new DriveGoogleAuthority($this->store,$this->gate,$this->oauth);$d=new DriveGoogleDispatcher($this->store,$this->gate,$this->oauth,$a,$this->http);
        $g=$a->issue($this->plan,$id,['folder'],2,($this->gate->clock=102),300);self::assertTrue((new DriveGrantJournal($this->store))->revoke($g));$before=count($this->http->requests);
        self::assertSame('BLOCKED',$d->dispatch($this->plan,$g,'folder',[],'original-docx',str_repeat('1',64),($this->gate->clock=103))['state']);
        $g=$a->issue($this->plan,$id,['folder'],2,($this->gate->clock=104),300);
        $key=DriveGoogleOAuth::key('connection',$id);$this->store->rows[$key]['ciphertext']='corrupt';
        self::assertSame('BLOCKED',$d->dispatch($this->plan,$g,'folder',[],'original-docx',str_repeat('2',64),($this->gate->clock=105))['state']);self::assertCount($before,$this->http->requests);
        $this->store->fail=true;self::assertNull($a->issue($this->plan,$id,['folder'],1,($this->gate->clock=106),300));
    }
    public function testRefreshTimeoutCannotReplayAndOldGenerationCannotDispatch():void {
        $id=$this->connect();$a=new DriveGoogleAuthority($this->store,$this->gate,$this->oauth);$d=new DriveGoogleDispatcher($this->store,$this->gate,$this->oauth,$a,$this->http);$g=$a->issue($this->plan,$id,['folder'],2,($this->gate->clock=102),300);
        $this->http->responses=[$this->json($this->token()),$this->json(['user'=>['permissionId'=>'synthetic-account-7']])];self::assertTrue($this->oauth->refresh($this->plan,$id,($this->gate->clock=103)));
        $before=count($this->http->requests);self::assertSame('BLOCKED',$d->dispatch($this->plan,$g,'folder',[],'original-docx',str_repeat('1',64),($this->gate->clock=104))['state']);self::assertCount($before,$this->http->requests);
        $this->http->responses=[new RuntimeException('refresh timeout')];self::assertFalse($this->oauth->refresh($this->plan,$id,($this->gate->clock=105)));$before=count($this->http->requests);
        self::assertFalse($this->oauth->refresh($this->plan,$id,($this->gate->clock=106)));self::assertCount($before,$this->http->requests);
    }

    public function testConnectionCannotBeReusedByDifferentOAuthApplication():void {
        $id=$this->connect();$client=\DigiForge\Integrations\CredentialVault::encryptExisting('synthetic-other-client','google:oauth-client:'.hash('sha256',"other.apps.googleusercontent.com\0https://example.test/google/callback"));
        $other=new DriveGoogleOAuth($this->store,$this->gate,$this->http,'other.apps.googleusercontent.com','https://example.test/google/callback',$client);
        self::assertNull($other->connection($this->plan,$id,($this->gate->clock=102)));
        self::assertFalse($other->refresh($this->plan,$id,($this->gate->clock=103)));
    }

}
