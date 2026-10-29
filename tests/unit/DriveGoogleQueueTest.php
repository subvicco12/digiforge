<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForge\DigitalFactory\{DriveGoogleOAuth,DriveGoogleAuthority,DriveGoogleDispatcher,DriveGoogleWorkflow,DriveQueueJobs,DriveTransferRequest};
require_once __DIR__.'/DriveGoogleOperationalTest.php';
require_once dirname(__DIR__,2).'/includes/DigitalFactory/DriveQueueJobs.php';
require_once dirname(__DIR__,2).'/includes/DigitalFactory/DriveGoogleWorkflow.php';

final class GoogleFixtureJobs implements DriveQueueJobs
{
    public array $rows=[];
    public array $wake=[];
    public bool $available=true;
    public bool $createAvailable=true;
    public function create(string $t,string $s,?int $dependency):?int{
        if(!$this->createAvailable)return null;
        foreach($this->rows as $id=>$r)if($r['transfer']===$t&&$r['stage']===$s)return $id;
        $id=count($this->rows)+1;$this->rows[$id]=['id'=>$id,'transfer'=>$t,'stage'=>$s,'state'=>'BLOCKED','owner'=>null,'dependency'=>$dependency];return $id;
    }
    public function read(int $id):?array{return $this->rows[$id]??null;}
    public function wake(int $id):bool{if(!$this->available)return false;if($this->rows[$id]['state']==='SUCCESS')return true;$this->rows[$id]['state']='QUEUED';$this->wake[]=$id;return true;}
    public function block(int $id):bool{if(($this->rows[$id]['state']??null)==='QUEUED')$this->rows[$id]['state']='BLOCKED';return ($this->rows[$id]['state']??null)==='BLOCKED';}
    public function claim(int $id,string $owner):bool{
        $r=$this->rows[$id];if($r['state']!=='QUEUED'||$r['dependency']!==null&&$this->rows[$r['dependency']]['state']!=='SUCCESS')return false;
        $this->rows[$id]['state']='RUNNING';$this->rows[$id]['owner']=$owner;return true;
    }
    public function finish(int $id,string $owner,string $state):bool{
        if($this->rows[$id]['state']!=='RUNNING'||$this->rows[$id]['owner']!==$owner)return false;
        $this->rows[$id]['state']=$state;$this->rows[$id]['owner']=null;return true;
    }
    public function active(int $id,string $owner):bool {return ($this->rows[$id]['state']??null)==='RUNNING'&&($this->rows[$id]['owner']??null)===$owner;}
}
final class DriveGoogleQueueTest extends TestCase
{
    private OperationalMemoryStore $s;private OperationalGate $g;private OperationalHttp $h;
    private DriveGoogleOAuth $oauth;private DriveGoogleWorkflow $w;private GoogleFixtureJobs $jobs;private array $b;private array $flow=[];
    protected function setUp():void{
        foreach(['DriveTransferRequest','DriveExecutionGrant','DriveGrantJournal','DriveHttpsProtocol','DriveOAuthResponse','DriveGoogleOAuth','DriveGoogleAuthority','DriveGoogleDispatcher'] as $n)require_once dirname(__DIR__,2).'/includes/DigitalFactory/'.$n.'.php';
        require_once dirname(__DIR__,2).'/includes/Integrations/CredentialVault.php';
        if(!defined('DIGIFORGE_CREDENTIAL_KEY'))define('DIGIFORGE_CREDENTIAL_KEY',str_repeat('synthetic-google-test-key-',2));
        $this->s=new OperationalMemoryStore();$this->g=new OperationalGate();$this->g->clock=100;$this->h=new OperationalHttp();$this->jobs=new GoogleFixtureJobs();
        $this->b=['id'=>str_repeat('a',64),'installation_sha256'=>str_repeat('b',64),'environment'=>'local','shop_key'=>'digital','folder_id'=>DriveTransferRequest::FOLDER,'actor_id'=>7,'product_id'=>9,'product_version_id'=>5,'revision_id'=>12,'source_sha256'=>hash('sha256','source')];
        $client=\DigiForge\Integrations\CredentialVault::encryptExisting('synthetic-secret','google:oauth-client:'.hash('sha256',"synthetic.apps.googleusercontent.com\0https://example.test/callback"));
        $this->oauth=new DriveGoogleOAuth($this->s,$this->g,$this->h,'synthetic.apps.googleusercontent.com','https://example.test/callback',$client);$a=new DriveGoogleAuthority($this->s,$this->g,$this->oauth);
        $d=new DriveGoogleDispatcher($this->s,$this->g,$this->oauth,$a,$this->h);
        $this->w=new DriveGoogleWorkflow($this->s,$this->g,$this->oauth,$a,$d,$this->jobs,fn($b)=>['bytes'=>'source','path'=>'synthetic'],fn($b,$bytes)=>['sha256'=>hash('sha256',$bytes),'storage_reference'=>'synthetic.pdf'],fn($b,$source,$pdf)=>['source_sha256'=>$b['source_sha256'],'visual_approved'=>false,'rights_certified'=>false,'production_authorized'=>false],'https://example.test/callback');
    }
    private function json(array $r):array{return ['status'=>200,'headers'=>['content-type'=>'application/json'],'body'=>json_encode($r)];}
    private function start():array{$r=$this->w->begin($this->b,'session');self::assertNotNull($r);parse_str(parse_url($r['authorization_url'],PHP_URL_QUERY),$q);return $q;}
    private function connect():void{
        $q=$this->start();$this->flow=$q;$this->h->responses=[$this->json(['access_token'=>'synthetic-access','refresh_token'=>'synthetic-refresh','token_type'=>'Bearer','expires_in'=>3600,'scope'=>'https://www.googleapis.com/auth/drive.file']),$this->json(['user'=>['permissionId'=>'synthetic-account']])];
        self::assertSame('CONNECTED',$this->w->callback(['state'=>$q['state'],'code'=>'synthetic-code'],'session')['state']);
    }
    public function testWordPressCallbackMustBeSupportedWithoutGeneralQueryAcceptance():void{
        $uri='https://example.test/wp-admin/admin-post.php?action=digiforge_google_oauth_callback';
        $cipher=\DigiForge\Integrations\CredentialVault::encryptExisting('synthetic-secret','google:oauth-client:'.hash('sha256',"synthetic.apps.googleusercontent.com\0".$uri));
        $accepted=true;try{new DriveGoogleOAuth($this->s,$this->g,$this->h,'synthetic.apps.googleusercontent.com',$uri,$cipher);}catch(InvalidArgumentException){$accepted=false;}
        self::assertTrue($accepted,'Canonical WordPress callback is rejected by the existing query-free profile.');
        foreach([$uri.'&redirect=https://evil.test',$uri.'&action=evil','https://example.test/wp-admin/admin-post.php?action=evil'] as $bad){$denied=false;try{new DriveGoogleOAuth($this->s,$this->g,$this->h,'synthetic.apps.googleusercontent.com',$bad,$cipher);}catch(InvalidArgumentException){$denied=true;}self::assertTrue($denied);}
    }
    public function testCallbackReplayWrongSessionAndExpiredStateNeverQueue():void{
        $q=$this->start();self::assertNull($this->w->callback(['state'=>$q['state'],'code'=>'code'],'foreign-session'));self::assertCount(0,$this->h->requests);
        $this->g->clock=701;self::assertNull($this->w->callback(['state'=>$q['state'],'code'=>'code'],'session'));self::assertCount(0,$this->h->requests);self::assertSame([],$this->jobs->rows);
    }
    public function testCallbackSuccessDoesNotGrantOrScheduleAndReplayDenies():void{
        $this->connect();self::assertSame([],$this->jobs->rows);$count=count($this->h->requests);
        $row=$this->w->plan($this->b['id']);self::assertSame('CONNECTED',$row['state']);
        self::assertNull($this->w->callback(['state'=>$this->flow['state'],'code'=>'code'],'session'));self::assertCount($count,$this->h->requests);
        self::assertNull($this->w->begin($this->b,'session'));
    }
    private function metadata(string $role,string $id):array{return ['id'=>$id,'mimeType'=>$role==='original_upload'?DriveTransferRequest::DOCX:DriveTransferRequest::DOC,'parents'=>[DriveTransferRequest::FOLDER],'trashed'=>false,'version'=>'1','capabilities'=>['canDownload'=>true],'appProperties'=>['digiforge_transfer'=>$this->b['id'],'digiforge_role'=>$role,'source_sha256'=>$this->b['source_sha256']]];}
    public function testSevenDurableStagesCompleteOnlyTechnicalQA():void{
        $this->connect();$submitted=$this->w->submit($this->b['id']);self::assertSame(1,$submitted['job_id']);self::assertSame(7,$this->w->actorForJob(1));
        $native=$this->metadata('native_conversion','native');$this->h->responses=[
            $this->json(['id'=>DriveTransferRequest::FOLDER,'mimeType'=>'application/vnd.google-apps.folder','trashed'=>false,'capabilities'=>['canAddChildren'=>true]]),$this->json($this->metadata('original_upload','original')),
            ['status'=>200,'headers'=>['content-type'=>DriveTransferRequest::DOCX],'body'=>'source'],$this->json($native),$this->json($native),['status'=>200,'headers'=>['content-type'=>'application/pdf'],'body'=>'%PDF-synthetic'],$this->json($native)];
        for($id=1;$id<=7;$id++){$r=$this->w->run($id);self::assertSame($id===7?'TECHNICAL_QA_COMPLETE':'QUEUED',$r['state']);self::assertFalse($r['production_authorized']);self::assertSame('SUCCESS',$this->jobs->rows[$id]['state']);}
        self::assertCount(9,$this->h->requests);$before=count($this->h->requests);self::assertSame('BLOCKED',$this->w->run(1)['state']);self::assertCount($before,$this->h->requests);
        self::assertStringNotContainsString('synthetic-access',json_encode($this->jobs->rows));
    }
    public function testStoppedAutomationRevocationMissingCredentialsAndCrossAccountCannotDispatch():void{
        $this->connect();$this->w->submit($this->b['id']);$before=count($this->h->requests);$this->g->allowed=false;
        self::assertSame('BLOCKED',$this->w->run(1)['state']);self::assertCount($before,$this->h->requests);$this->g->allowed=true;
        foreach($this->s->rows as $key=>&$row)if(str_contains($key,'_connection_')){$row['account_id']='foreign';}unset($row);
        self::assertSame('BLOCKED',$this->w->run(1)['state']);self::assertCount($before,$this->h->requests);
    }
    public function testInterruptedCreateDoesNotRepeatAndUsesExplicitReadReconciliation():void{
        $this->connect();$this->w->submit($this->b['id']);$this->h->responses=[$this->json(['id'=>DriveTransferRequest::FOLDER,'mimeType'=>'application/vnd.google-apps.folder','trashed'=>false,'capabilities'=>['canAddChildren'=>true]])];self::assertSame('QUEUED',$this->w->run(1)['state']);
        $this->h->responses=[new RuntimeException('interrupted after create')];self::assertSame('UNKNOWN',$this->w->run(2)['state']);self::assertSame('HUMAN_REVIEW',$this->jobs->rows[2]['state']);$before=count($this->h->requests);
        self::assertSame('BLOCKED',$this->w->run(2)['state']);self::assertCount($before,$this->h->requests);
        $reconcile=$this->w->reconcile($this->b['id'],'original_upload');self::assertSame(3,$reconcile);$this->h->responses=[$this->json(['files'=>[$this->metadata('original_upload','original')]])];self::assertSame('QUEUED',$this->w->run(3)['state']);self::assertSame('GET',$this->h->requests[array_key_last($this->h->requests)][1]['method']);
    }
    public function testExpiredGrantAndMissingOrRevokedCredentialNeverSend():void{
        foreach(['expired','missing','revoked'] as $case){
            $this->setUp();$this->connect();$this->w->submit($this->b['id']);$before=count($this->h->requests);
            if($case==='expired')$this->g->clock=1001;
            else{foreach($this->s->rows as $key=>&$row)if(str_contains($key,'_connection_')){if($case==='missing')$row['ciphertext']='';else $row['state']='REVOKED';}unset($row);}
            self::assertSame('BLOCKED',$this->w->run(1)['state'],$case);self::assertCount($before,$this->h->requests);
        }
    }
    public function testInterruptedCheckpointAndStoppedAfterResponseNeverAdvance():void{
        $this->connect();$this->w->submit($this->b['id']);$this->h->responses=[$this->json(['id'=>DriveTransferRequest::FOLDER,'mimeType'=>'application/vnd.google-apps.folder','trashed'=>false,'capabilities'=>['canAddChildren'=>true]])];
        $this->h->during=function(){$this->s->fail=true;};self::assertSame('UNKNOWN',$this->w->run(1)['state']);self::assertSame('HUMAN_REVIEW',$this->jobs->rows[1]['state']);self::assertCount(1,$this->jobs->rows);
        $this->s->fail=false;$before=count($this->h->requests);self::assertSame('BLOCKED',$this->w->run(1)['state']);self::assertCount($before,$this->h->requests);
        self::assertNull($this->w->reconcile($this->b['id'],'folder'));
    }
    public function testForgedQueueReferenceAndUnavailableSchedulerCannotExecute():void{
        $this->connect();$this->jobs->available=false;self::assertNull($this->w->submit($this->b['id']));$before=count($this->h->requests);self::assertSame('BLOCKED',$this->jobs->rows[1]['state']);
        $this->jobs->rows[9]=['id'=>9,'transfer'=>str_repeat('f',64),'stage'=>'original_upload','state'=>'QUEUED','owner'=>null,'dependency'=>null];
        self::assertNull($this->w->actorForJob(9));self::assertSame('BLOCKED',$this->w->run(9)['state']);self::assertCount($before,$this->h->requests);
    }

    public function testExpiredGrantRequiresExplicitRenewalAndRetainsUnknownCreateClaim():void{
        $this->connect();$this->w->submit($this->b['id']);$this->h->responses=[$this->json(['id'=>DriveTransferRequest::FOLDER,'mimeType'=>'application/vnd.google-apps.folder','trashed'=>false,'capabilities'=>['canAddChildren'=>true]])];$this->w->run(1);
        $this->h->responses=[new RuntimeException('uncertain create')];$this->w->run(2);$this->g->clock=1001;
        self::assertNull($this->w->reconcile($this->b['id'],'original_upload'));
        self::assertTrue(method_exists($this->w,'renew'),'Expired grants have no safe explicit renewal route.');
        self::assertTrue($this->w->renew($this->b['id']));$before=count($this->h->requests);
        $id=$this->w->reconcile($this->b['id'],'original_upload');self::assertIsInt($id);$this->h->responses=[$this->json(['files'=>[$this->metadata('original_upload','original')]])];self::assertSame('QUEUED',$this->w->run($id)['state']);self::assertCount($before+1,$this->h->requests);
        self::assertSame('GET',$this->h->requests[array_key_last($this->h->requests)][1]['method']);
        $revoked=0;foreach($this->s->rows as $key=>$row)if(str_contains($key,'_grant_')&&$row['revoked'])$revoked++;self::assertSame(1,$revoked);
    }

    public function testExplicitRefreshDoesNotSilentlyRenewOrDispatch():void{
        $this->connect();$this->w->submit($this->b['id']);$this->g->clock=4000;
        $this->h->responses=[$this->json(['access_token'=>'synthetic-refreshed','token_type'=>'Bearer','expires_in'=>3600,'scope'=>'https://www.googleapis.com/auth/drive.file']),$this->json(['user'=>['permissionId'=>'synthetic-account']])];
        self::assertTrue($this->w->refresh($this->b['id']));$before=count($this->h->requests);self::assertSame('BLOCKED',$this->w->run(1)['state']);self::assertCount($before,$this->h->requests);
        self::assertTrue($this->w->renew($this->b['id']));self::assertCount($before,$this->h->requests);
    }

    public static function crashedCreates():array{return [['original_upload',2,'wake'],['native_conversion',4,'wake'],['original_upload',2,'create'],['native_conversion',4,'create']];}
    /** @dataProvider crashedCreates */
    public function testReconciledCrashCanResumeAfterSchedulerFailureWithoutRepeatingCreate(string $role,int $failedJob,string $outage):void{
        $this->connect();$this->w->submit($this->b['id']);
        $native=$this->metadata('native_conversion','native');
        $responses=[
            $this->json(['id'=>DriveTransferRequest::FOLDER,'mimeType'=>'application/vnd.google-apps.folder','trashed'=>false,'capabilities'=>['canAddChildren'=>true]]),
            $this->json($this->metadata('original_upload','original')),
            ['status'=>200,'headers'=>['content-type'=>DriveTransferRequest::DOCX],'body'=>'source'],
            $this->json($native),$this->json($native),
            ['status'=>200,'headers'=>['content-type'=>'application/pdf'],'body'=>'%PDF-synthetic'],$this->json($native),
        ];
        $this->h->responses=array_slice($responses,0,$failedJob-1);
        for($id=1;$id<$failedJob;$id++)self::assertSame('QUEUED',$this->w->run($id)['state']);
        $this->h->responses=[new RuntimeException('uncertain create')];
        self::assertSame('UNKNOWN',$this->w->run($failedJob)['state']);
        // A killed worker can leave its durable claim PENDING; the old job is no longer running.
        $key=DriveGoogleOAuth::key('operation','workflow:transfer:'.$this->b['id']);
        $this->s->rows[$key]['claims'][$role]['state']='PENDING';
        $claim=$this->s->rows[$key]['claims'][$role];$before=count($this->h->requests);
        self::assertSame('UNKNOWN',$this->w->submit($this->b['id'])['state']);
        self::assertCount($before,$this->h->requests);
        $reconcile=$this->w->reconcile($this->b['id'],$role);self::assertIsInt($reconcile);
        $this->h->responses=[$this->json(['files'=>[$this->metadata($role,$role==='original_upload'?'original':'native')]])];
        if($outage==='wake')$this->jobs->available=false;else $this->jobs->createAvailable=false;
        self::assertSame('BLOCKED',$this->w->run($reconcile)['state']);
        self::assertSame('SUCCESS',$this->jobs->rows[$reconcile]['state']);
        $this->jobs->available=true;$this->jobs->createAvailable=true;$before=count($this->h->requests);
        $this->g->allowed=false;self::assertNull($this->w->submit($this->b['id']));
        $this->g->allowed=true;$this->g->clock=1001;
        self::assertNull($this->w->submit($this->b['id']),'Recovered evidence cannot renew expired authority.');
        self::assertTrue($this->w->renew($this->b['id']));
        self::assertCount($before,$this->h->requests,'Explicit renewal never dispatches.');
        $resumed=$this->w->submit($this->b['id']);
        self::assertSame('QUEUED',$resumed['state']);
        self::assertCount($before,$this->h->requests,'Resumption only wakes the persisted next job.');
        self::assertSame($claim,$this->s->rows[$key]['claims'][$role],'Crash evidence must remain immutable.');
        $this->h->responses=array_slice($responses,$failedJob);
        for($id=$resumed['job_id'];$id<=8;$id++){
            $result=$this->w->run($id);self::assertSame($id===8?'TECHNICAL_QA_COMPLETE':'QUEUED',$result['state']);
            self::assertFalse($result['production_authorized']);
        }
        self::assertCount(10,$this->h->requests,'Only the failed create and one GET reconciliation are added.');
        self::assertSame($claim,$this->s->rows[$key]['claims'][$role]);
    }

    public function testUnprovenResultCannotSuppressPendingCrashClaim():void{
        $this->connect();$this->w->submit($this->b['id']);
        $key=DriveGoogleOAuth::key('operation','workflow:transfer:'.$this->b['id']);
        $this->s->rows[$key]['claims']['original_upload']=['attempt'=>str_repeat('c',64),'state'=>'PENDING'];
        $this->s->rows[$key]['results']['original_upload']=['status'=>200];
        $before=count($this->h->requests);
        self::assertSame('UNKNOWN',$this->w->submit($this->b['id'])['state']);
        self::assertCount($before,$this->h->requests);
    }

    public function testConcurrentReconciliationCannotInterfereWithRunningCreate():void{
        $this->connect();$this->w->submit($this->b['id']);
        $this->h->responses=[$this->json(['id'=>DriveTransferRequest::FOLDER,'mimeType'=>'application/vnd.google-apps.folder','trashed'=>false,'capabilities'=>['canAddChildren'=>true]])];
        self::assertSame('QUEUED',$this->w->run(1)['state']);
        $reconciliation='not_called';
        $this->h->during=function()use(&$reconciliation){
            $reconciliation=$this->w->reconcile($this->b['id'],'original_upload');
        };
        $this->h->responses=[$this->json($this->metadata('original_upload','original'))];
        $result=$this->w->run(2);
        self::assertNull($reconciliation,'The portal running-job restriction must also hold at the server boundary.');
        self::assertSame('QUEUED',$result['state']);
        self::assertSame('SUCCESS',$this->jobs->rows[2]['state']);
        self::assertSame('original_readback',$this->jobs->rows[3]['stage']);
        self::assertCount(4,$this->h->requests);
    }

}
