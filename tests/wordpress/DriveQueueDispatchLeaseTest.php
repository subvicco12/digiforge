<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
use DigiForge\DigitalFactory\{DriveGrantStore,DriveGoogleGate,DriveGoogleHttp,DriveGoogleOAuth,DriveGoogleAuthority,DriveGoogleDispatcher,DriveGoogleWorkflow,DriveWordPressQueueJobs,DriveTransferRequest};
use DigiForge\Database\Tables;
use DigiForge\Queue\JobRepository;

/** Real queue rows/leases, synthetic authority/HTTP only; production locks stay held. */
final class DriveQueueDispatchLeaseTest extends WP_UnitTestCase
{
    private ?Closure $onSource=null;
    private int $external=0;
    private Closure $deny;
    protected function setUp():void {
        parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();ShopAccessFixture::reset();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));ShopAccessFixture::authorizeActor(get_current_user_id(),['digital'],['manage_digiforge_products']);
        $this->deny=function($pre){if($pre!==false)return $pre;++$this->external;return new WP_Error('offline_only');};add_filter('pre_http_request',$this->deny);
    }
    protected function tearDown():void {
        remove_filter('pre_http_request',$this->deny);self::assertSame(0,$this->external);self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertFalse(DigiForge\Core\Settings::get('automation_armed',false));parent::tearDown();
    }
    private function fixture():array {
        $store=new class implements DriveGrantStore {
            public array $rows=[];
            public function read(string $key):?array{return $this->rows[$key]??[];}
            public function compareAndSwap(string $key,array $old,array $new):bool{if(($this->rows[$key]??[])!==$old)return false;$this->rows[$key]=$new;return true;}
        };
        $gate=new class implements DriveGoogleGate {
            public ?Closure $during=null;
            public function now():int{return time();}
            public function owns(array $b):bool{return ($b['actor_id']??null)===get_current_user_id()&&DigiForge\Security\ShopAccess::authorize('digital','manage_digiforge_products')===true;}
            public function allows(array $b):bool{if($this->during!==null){$f=$this->during;$this->during=null;$f();}return $this->owns($b);}
        };
        $http=new class implements DriveGoogleHttp {
            public array $responses=[];public array $requests=[];public ?Closure $during=null;
            public function exchange(array $b,string $url,array $args):array{$this->requests[]=['url'=>$url,'method'=>$args['method']??'GET'];if($this->during!==null){$f=$this->during;$this->during=null;$f();}return array_shift($this->responses)??[];}
        };
        $b=['id'=>hash('sha256',wp_generate_uuid4()),'installation_sha256'=>hash('sha256',site_url()),'environment'=>'local','shop_key'=>'digital','folder_id'=>DriveTransferRequest::FOLDER,'actor_id'=>get_current_user_id(),'product_id'=>9,'product_version_id'=>5,'revision_id'=>12,'source_sha256'=>hash('sha256','synthetic-docx')];
        $uri='https://example.test/callback';$client='synthetic.apps.googleusercontent.com';$cipher=DigiForge\Integrations\CredentialVault::encrypt('synthetic-client','google:oauth-client:'.hash('sha256',$client."\0".$uri));
        $oauth=new DriveGoogleOAuth($store,$gate,$http,$client,$uri,$cipher);$authority=new DriveGoogleAuthority($store,$gate,$oauth);$dispatcher=new DriveGoogleDispatcher($store,$gate,$oauth,$authority,$http);$jobs=new DriveWordPressQueueJobs();
        $workflow=new DriveGoogleWorkflow($store,$gate,$oauth,$authority,$dispatcher,$jobs,function() {if($this->onSource!==null){$f=$this->onSource;$this->onSource=null;$f();}return ['bytes'=>'synthetic-docx'];},fn($b,$bytes)=>['sha256'=>hash('sha256',$bytes),'storage_reference'=>'synthetic.pdf'],fn($b)=>['source_sha256'=>$b['source_sha256'],'visual_approved'=>false,'rights_certified'=>false,'production_authorized'=>false],$uri);
        $start=$workflow->begin($b,'synthetic-session');self::assertIsArray($start);parse_str(parse_url($start['authorization_url'],PHP_URL_QUERY),$q);
        $http->responses=[$this->json(['access_token'=>'synthetic-access','refresh_token'=>'synthetic-refresh','token_type'=>'Bearer','expires_in'=>3600,'scope'=>'https://www.googleapis.com/auth/drive.file']),$this->json(['user'=>['permissionId'=>'synthetic-account']])];
        self::assertSame('CONNECTED',$workflow->callback(['state'=>$q['state'],'code'=>'synthetic-code'],'synthetic-session')['state']);$workflow->submit($b['id']);
        return compact('store','gate','http','b','jobs','workflow');
    }
    private function json(array $r):array{return ['status'=>200,'headers'=>['content-type'=>'application/json'],'body'=>wp_json_encode($r)];}
    private function job(array $f,string $stage):int {
        global $wpdb;$id=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Tables::jobs().' WHERE idempotency_key=%s','google:'.hash('sha256',$f['b']['id'].':'.$stage)));self::assertGreaterThan(0,$id);
        if($f['jobs']->read($id)['state']==='BLOCKED')self::assertTrue((new JobRepository())->transition($id,'BLOCKED','QUEUED'));return $id;
    }
    private function metadata(array $b,string $role,string $id):array{return ['id'=>$id,'mimeType'=>$role==='original_upload'?DriveTransferRequest::DOCX:DriveTransferRequest::DOC,'parents'=>[DriveTransferRequest::FOLDER],'trashed'=>false,'version'=>'1','capabilities'=>['canDownload'=>true],'appProperties'=>['digiforge_transfer'=>$b['id'],'digiforge_role'=>$role,'source_sha256'=>$b['source_sha256']]];}
    private function uploadFixture():array {
        $f=$this->fixture();$f['http']->responses=[$this->json(['id'=>DriveTransferRequest::FOLDER,'mimeType'=>'application/vnd.google-apps.folder','trashed'=>false,'capabilities'=>['canAddChildren'=>true]])];$folder=$this->job($f,'folder');$f['workflow']->run($folder);self::assertSame('SUCCESS',$f['jobs']->read($folder)['state']);$f['job']=$this->job($f,'original_upload');$f['http']->responses=[$this->json($this->metadata($f['b'],'original_upload','synthetic-original'))];return $f;
    }
    public static function losses():array{return [['cancel'],['expired'],['reassigned'],['cancel_during_authorization']];}
    /** @dataProvider losses */
    public function testLostLeaseOrCancellationCannotSendUpload(string $case):void {
        $f=$this->uploadFixture();$lose=function()use($f,$case){global $wpdb;if(str_starts_with($case,'cancel'))self::assertTrue((new JobRepository())->transition($f['job'],'RUNNING','CANCELLED'));else self::assertSame(1,$wpdb->update(Tables::jobs(),$case==='expired'?['lease_expires_at'=>gmdate('Y-m-d H:i:s',time()-1)]:['locked_by'=>'different-worker'],['id'=>$f['job']]));};
        $this->onSource=$case==='cancel_during_authorization'?function()use($f,$lose){$f['gate']->during=$lose;}:$lose;
        $before=count($f['http']->requests);$out=$f['workflow']->run($f['job']);self::assertSame($before,count($f['http']->requests),'Cancelled/stale worker must not reach the mock HTTPS sender.');self::assertNotSame('TECHNICAL_QA_COMPLETE',$out['state']);$f['workflow']->run($f['job']);self::assertSame($before,count($f['http']->requests));
        if($case==='expired'){self::assertTrue((new JobRepository())->recoverExpiredLease($f['job']));self::assertFalse((new JobRepository())->recoverExpiredLease($f['job']));self::assertSame('HUMAN_REVIEW',$f['jobs']->read($f['job'])['state']);$f['workflow']->run($f['job']);self::assertSame($before,count($f['http']->requests));}
    }
    public function testCancellationAfterSendRetainsUnknownWithoutPromotingCheckpoint():void {
        $f=$this->uploadFixture();$f['http']->during=fn()=>self::assertTrue((new JobRepository())->transition($f['job'],'RUNNING','CANCELLED'));
        $before=count($f['http']->requests);$out=$f['workflow']->run($f['job']);self::assertSame($before+1,count($f['http']->requests));self::assertSame('UNKNOWN',$out['state']);
        $record=$f['store']->read(DriveGoogleOAuth::key('operation','workflow:transfer:'.$f['b']['id']));self::assertArrayNotHasKey('original_upload',$record['results']);self::assertSame('CANCELLED',$f['jobs']->read($f['job'])['state']);$f['workflow']->run($f['job']);self::assertSame($before+1,count($f['http']->requests));
        // Only an explicit read reconciliation can recover a possible remote create.
        $f['workflow']->reconcile($f['b']['id'],'original_upload');$rec=$this->job($f,'reconcile_original_upload');$f['http']->responses=[$this->json(['files'=>[$this->metadata($f['b'],'original_upload','synthetic-original')]])];$f['workflow']->run($rec);self::assertSame('SUCCESS',$f['jobs']->read($rec)['state']);
        $f['workflow']->submit($f['b']['id']);$read=$this->job($f,'original_readback');$f['http']->responses=[['status'=>200,'headers'=>['content-type'=>DriveTransferRequest::DOCX],'body'=>'synthetic-docx']];$f['workflow']->run($read);self::assertSame('SUCCESS',$f['jobs']->read($read)['state']);self::assertSame('CANCELLED',$f['jobs']->read($f['job'])['state']);
        $creates=array_filter($f['http']->requests,static fn(array $r):bool=>$r['method']==='POST'&&str_contains($r['url'],'/upload/drive/v3/files'));self::assertCount(1,$creates);

    }
    public static function finishLosses():array{return [['expired'],['owner_race'],['expiry_race']];}
    /** @dataProvider finishLosses */
    public function testStaleWorkerCannotCommitSuccess(string $case):void {
        global $wpdb;$jobs=new DriveWordPressQueueJobs();$id=$jobs->create(hash('sha256',wp_generate_uuid4()),'folder',null);self::assertIsInt($id);$repo=new JobRepository();self::assertTrue($repo->transition($id,'BLOCKED','QUEUED'));self::assertTrue($jobs->claim($id,'worker-a'));
        if($case==='expired')self::assertSame(1,$wpdb->update(Tables::jobs(),['lease_expires_at'=>gmdate('Y-m-d H:i:s',time()-1)],['id'=>$id]));
        $changed=false;$table=Tables::jobs();$race=function(string $sql)use($case,$id,$table,&$changed):string{if($case!=='expired'&&!$changed&&str_starts_with($sql,'UPDATE '.$table.' SET state')){$changed=true;global $wpdb;self::assertSame(1,$wpdb->update($table,$case==='owner_race'?['locked_by'=>'worker-b']:['lease_expires_at'=>gmdate('Y-m-d H:i:s',time()-1)],['id'=>$id]));}return $sql;};add_filter('query',$race);
        try{self::assertFalse($jobs->finish($id,'worker-a','SUCCESS'));}finally{remove_filter('query',$race);}
        if($case!=='expired')self::assertTrue($changed);self::assertSame('RUNNING',$jobs->read($id)['state']);
    }
    public function testSevenNativeQueueStagesRetainTechnicalOnlyCompletionAndExactReplay():void {
        $f=$this->fixture();$native=$this->metadata($f['b'],'native_conversion','synthetic-native');$f['http']->responses=[$this->json(['id'=>DriveTransferRequest::FOLDER,'mimeType'=>'application/vnd.google-apps.folder','trashed'=>false,'capabilities'=>['canAddChildren'=>true]]),$this->json($this->metadata($f['b'],'original_upload','synthetic-original')),['status'=>200,'headers'=>['content-type'=>DriveTransferRequest::DOCX],'body'=>'synthetic-docx'],$this->json($native),$this->json($native),['status'=>200,'headers'=>['content-type'=>'application/pdf'],'body'=>'%PDF-synthetic'],$this->json($native)];
        foreach(['folder','original_upload','original_readback','native_conversion','native_readback','pdf_export','native_final']as $stage){$id=$this->job($f,$stage);$f['workflow']->run($id);self::assertSame('SUCCESS',$f['jobs']->read($id)['state']);}
        self::assertCount(9,$f['http']->requests);$key=DriveGoogleOAuth::key('operation','workflow:transfer:'.$f['b']['id']);$record=$f['store']->read($key);self::assertSame('TECHNICAL_QA_COMPLETE',$record['state']);self::assertFalse($record['results']['native_final']['production_authorized']);self::assertSame('BLOCKED',$f['workflow']->run($id)['state']);self::assertCount(9,$f['http']->requests);
    }
    public function testWordPressSenderRechecksWorkerAfterItsOwnGate():void {
        $f=$this->fixture();$id=$this->job($f,'folder');self::assertTrue($f['jobs']->claim($id,'worker-a'));
        $f['gate']->during=fn()=>self::assertTrue((new JobRepository())->transition($id,'RUNNING','CANCELLED'));
        $request=DriveTransferRequest::build('folder',$f['b'],[]);$wire=DigiForge\DigitalFactory\DriveHttpsProtocol::encode($request,$f['b'],[],'synthetic-docx');$wire['args']['headers']['Authorization']='Bearer synthetic-access';
        $http=new DigiForge\DigitalFactory\DriveWordPressGoogleHttp($f['gate'],fn():bool=>$f['jobs']->active($id,'worker-a'));
        $blocked=false;try{$http->exchange($f['b'],$wire['url'],$wire['args']);}catch(RuntimeException){$blocked=true;}
        self::assertTrue($blocked);self::assertSame(0,$this->external);
    }

    public static function completionRaces():array{return [['cancel'],['owner']];}
    /** @dataProvider completionRaces */
    public function testCompletionRaceCannotLeaveTechnicalQaPromoted(string $case):void {
        $f=$this->fixture();$native=$this->metadata($f['b'],'native_conversion','synthetic-native');$f['http']->responses=[$this->json(['id'=>DriveTransferRequest::FOLDER,'mimeType'=>'application/vnd.google-apps.folder','trashed'=>false,'capabilities'=>['canAddChildren'=>true]]),$this->json($this->metadata($f['b'],'original_upload','synthetic-original')),['status'=>200,'headers'=>['content-type'=>DriveTransferRequest::DOCX],'body'=>'synthetic-docx'],$this->json($native),$this->json($native),['status'=>200,'headers'=>['content-type'=>'application/pdf'],'body'=>'%PDF-synthetic'],$this->json($native)];
        foreach(['folder','original_upload','original_readback','native_conversion','native_readback','pdf_export']as $stage){$id=$this->job($f,$stage);$f['workflow']->run($id);self::assertSame('SUCCESS',$f['jobs']->read($id)['state']);}
        $id=$this->job($f,'native_final');$changed=false;$table=Tables::jobs();
        $race=function(string $sql)use($id,$case,$table,&$changed):string{if(!$changed&&str_starts_with($sql,'UPDATE '.$table.' SET state')&&str_contains($sql,"'SUCCESS'")){$changed=true;global $wpdb;self::assertSame(1,$wpdb->update($table,$case==='cancel'?['state'=>'CANCELLED']:['locked_by'=>'worker-b'],['id'=>$id]));}return $sql;};add_filter('query',$race);
        try{$out=$f['workflow']->run($id);}finally{remove_filter('query',$race);}
        self::assertTrue($changed);self::assertSame('UNKNOWN',$out['state']);$key=DriveGoogleOAuth::key('operation','workflow:transfer:'.$f['b']['id']);$record=$f['store']->read($key);self::assertSame('UNKNOWN',$record['state']);self::assertSame('UNKNOWN',$record['claims']['native_final']['state']);self::assertCount(9,$f['http']->requests);self::assertSame('UNKNOWN',$f['workflow']->submit($f['b']['id'])['state']);self::assertCount(9,$f['http']->requests);
    }

    public function testUnavailableLeaseReadDeniesSenderAndLeavesRecoveryExplicit():void {
        $f=$this->uploadFixture();$table=Tables::jobs();$failed=false;$fail=static function(string $sql)use($table,&$failed):string{if(str_starts_with($sql,'SELECT state,locked_by,lease_expires_at FROM '.$table)){$failed=true;return str_replace($table,$table.'_unavailable',$sql);}return $sql;};
        $this->onSource=static function()use($fail){add_filter('query',$fail);};global $wpdb;$previous=$wpdb->suppress_errors(true);$before=count($f['http']->requests);
        try{$out=$f['workflow']->run($f['job']);}finally{remove_filter('query',$fail);$wpdb->suppress_errors($previous);$wpdb->last_error='';}
        self::assertTrue($failed);self::assertSame('UNKNOWN',$out['state']);self::assertSame($before,count($f['http']->requests));$f['workflow']->run($f['job']);self::assertSame($before,count($f['http']->requests));
    }

    public function testComposedWorkerCannotRelaxAnEarlierSenderVeto():void {
        $f=$this->fixture();$request=DriveTransferRequest::build('folder',$f['b'],[]);$wire=DigiForge\DigitalFactory\DriveHttpsProtocol::encode($request,$f['b'],[],'synthetic-docx');$wire['args']['headers']['Authorization']='Bearer synthetic-access';
        $http=(new DigiForge\DigitalFactory\DriveWordPressGoogleHttp($f['gate'],static fn():bool=>false))->forWorker(static fn():bool=>true);
        $blocked=false;try{$http->exchange($f['b'],$wire['url'],$wire['args']);}catch(RuntimeException){$blocked=true;}self::assertTrue($blocked);self::assertSame(0,$this->external);
    }

    public function testWordPressTransportPreservesBoundedMockBytesAndRedactsHeaders():void {
        $f=$this->fixture();$id=$this->job($f,'folder');self::assertTrue($f['jobs']->claim($id,'worker-a'));$request=DriveTransferRequest::build('folder',$f['b'],[]);$wire=DigiForge\DigitalFactory\DriveHttpsProtocol::encode($request,$f['b'],[],'synthetic-docx');$wire['args']['headers']['Authorization']='Bearer synthetic-access';$wire['args']['limit_response_size']=32;
        $http=(new DigiForge\DigitalFactory\DriveWordPressGoogleHttp($f['gate']))->forWorker(fn():bool=>$f['jobs']->active($id,'worker-a'));$bytes="%PDF-\x00\xff";$body=$bytes;$called=0;
        $respond=static function($pre,$args,$url)use(&$body,&$called,$wire):array{self::assertSame($wire['url'],$url);++$called;return ['response'=>['code'=>200],'headers'=>['content-type'=>'application/pdf','authorization'=>'synthetic-never-echo'],'body'=>$body];};add_filter('pre_http_request',$respond,1,3);
        try{$good=$http->exchange($f['b'],$wire['url'],$wire['args']);self::assertSame($bytes,$good['body']);self::assertSame(['content-type'=>'application/pdf'],$good['headers']);
            $body=['malformed'];self::assertSame(0,$http->exchange($f['b'],$wire['url'],$wire['args'])['status']);$body=str_repeat('x',33);self::assertSame(0,$http->exchange($f['b'],$wire['url'],$wire['args'])['status']);
        }finally{remove_filter('pre_http_request',$respond,1);}self::assertSame(3,$called);self::assertSame(0,$this->external);
    }

}
