<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/NativeBusinessOrderFixture.php';

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Orders\HeldFulfillmentPreparation;
use DigiForge\Orders\Repository;
use DigiForge\Queue\{JobRepository,Shop2HeldJobRecovery};

/** Existing local queue composition only; no native/provider recovery is simulated. */
final class Shop2HeldJobRecoveryTest extends WP_UnitTestCase
{
    use NativeBusinessOrderFixture;
    private array $queueSnapshot=[];
    private array $intentSnapshot=[];
    private $http;
    private int $httpCalls=0;

    public function setUp():void
    {
        parent::setUp();self::assertTrue(class_exists(Shop2HeldJobRecovery::class),'Scoped held-job inspection and quarantine composition is missing.');
        remove_filter('query',[$this,'_create_temporary_tables']);remove_filter('query',[$this,'_drop_temporary_tables']);
        DigiForge\Core\Activator::activate();global $wpdb;
        $this->queueSnapshot=$wpdb->get_results('SELECT * FROM '.Tables::jobs(),ARRAY_A);
        $this->intentSnapshot=$wpdb->get_results('SELECT * FROM '.Tables::fulfillment_intents(),ARRAY_A);
        $this->bootOrderFixture();Settings::protectProduction();
        $this->http=function(){++$this->httpCalls;return new WP_Error('unexpected_http');};add_filter('pre_http_request',$this->http);
    }
    public function tearDown():void
    {
        if($this->http!==null)remove_filter('pre_http_request',$this->http);
        try {if(isset($this->fixture)){$this->restoreOrderFixture();global $wpdb;foreach([Tables::jobs()=>$this->queueSnapshot,Tables::fulfillment_intents()=>$this->intentSnapshot]as $table=>$rows){$wpdb->query('DELETE FROM '.$table);foreach($rows as $row)$wpdb->insert($table,$row,array_fill(0,count($row),'%s'));}Settings::protectProduction();}self::assertSame(0,$this->httpCalls);}finally{parent::tearDown();}
    }

    public function testActualEnqueueIsStableBlockedMetadataAndFreshKeyCannotRecreateIt():void
    {
        [$order,$intent]=$this->heldFixture();$prepared=$this->prepared($order,$intent);$key=$this->key($order,$intent,$prepared);$service=new Shop2HeldJobRecovery();
        $job=$service->enqueue('personalized_pod',$order,$intent,$prepared['current_evidence_hash'],$key);self::assertNotWPError($job);
        self::assertSame('BLOCKED',$job['job']['state']);self::assertFalse($job['quarantine_eligible']);self::assertFalse($job['external_execution_authorized']);
        $before=$this->row($job['job_id']);$again=$service->enqueue('personalized_pod',$order,$intent,$prepared['current_evidence_hash'],$key);
        self::assertNotWPError($again);self::assertSame($job['job_id'],$again['job_id']);self::assertSame($before,$this->row($job['job_id']));
        self::assertWPError($service->enqueue('personalized_pod',$order,$intent,$prepared['current_evidence_hash'],hash('sha256','fresh-confirmation')));
        $list=$service->forIntent('personalized_pod',$order,$intent);self::assertNotWPError($list);self::assertCount(1,$list['items']);
        self::assertArrayNotHasKey('payload',$job['job']);self::assertArrayNotHasKey('locked_by',$job['job']);self::assertTrue(Settings::safety_locked());
    }

    public function testEnqueueRetainsActualPhotoReadLeaseUntilItsOwnedCommit():void
    {
        [$order,$intent]=$this->heldFixture();$prepared=$this->prepared($order,$intent);global $wpdb;$database=$wpdb;
        $other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->set_prefix($database->prefix);
        $name='df-photo-producer-'.substr(hash('sha256',$database->get_var('SELECT DATABASE()').'|'.$database->prefix),0,40);
        $fired=false;$active=null;$blocked=null;$owner=null;
        $observe=static function(string $sql)use($database,$other,$name,&$fired,&$active,&$blocked,&$owner):string{
            if(!$fired&&str_starts_with($sql,'INSERT INTO `'.Tables::jobs().'`')){
                $fired=true;$active=$database->get_var('SELECT @@session.in_transaction');
                $owner=$database->get_var($database->prepare('SELECT IS_USED_LOCK(%s)',$name));
                $blocked=$other->get_var($other->prepare('SELECT GET_LOCK(%s,0)',$name));
            }return $sql;
        };
        add_filter('query',$observe);
        try {$job=(new Shop2HeldJobRecovery())->enqueue('personalized_pod',$order,$intent,$prepared['current_evidence_hash'],$this->key($order,$intent,$prepared));}
        finally {remove_filter('query',$observe);}
        try {
            self::assertTrue($fired);self::assertNotWPError($job);self::assertSame('1',(string)$active);self::assertSame('0',(string)$blocked);
            self::assertSame((string)$database->get_var('SELECT CONNECTION_ID()'),(string)$owner);
            self::assertSame('0',(string)$database->get_var('SELECT @@session.in_transaction'));
            self::assertSame('1',(string)$other->get_var($other->prepare('SELECT GET_LOCK(%s,0)',$name)),'Actual A lease must be released only after the local transaction ends.');
        }finally{$other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)',$name));$other->close();}
    }

    public function testEnqueueCannotAdmitOrCommitCallerTransaction():void
    {
        [$order,$intent]=$this->heldFixture();$prepared=$this->prepared($order,$intent);global $wpdb;
        $name='held-context-caller-'.wp_generate_uuid4();$other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->set_prefix($wpdb->prefix);
        self::assertNotFalse($wpdb->query('START TRANSACTION'));self::assertSame(1,$wpdb->insert($wpdb->options,['option_name'=>$name,'option_value'=>'uncommitted','autoload'=>'no']));
        try {
            $result=(new Shop2HeldJobRecovery())->enqueue('personalized_pod',$order,$intent,$prepared['current_evidence_hash'],$this->key($order,$intent,$prepared));
            self::assertWPError($result);self::assertSame('NOT_ATTEMPTED',$result->get_error_data()['local_commit_state']);
            self::assertSame('1',(string)$wpdb->get_var('SELECT @@session.in_transaction'));self::assertNull($other->get_var($other->prepare('SELECT option_value FROM '.$other->options.' WHERE option_name=%s',$name)));
        }finally{$wpdb->query('ROLLBACK');$other->close();}
    }

    public function testRevocationDuringFinalPhotoLeaseReleaseDeniesCommittedEnqueueResponse():void
    {
        [$order,$intent]=$this->heldFixture();$prepared=$this->prepared($order,$intent);$inserted=false;$fired=false;
        $revoke=static function(string $sql)use(&$inserted,&$fired):string{
            if(str_starts_with($sql,'INSERT INTO `'.Tables::jobs().'`'))$inserted=true;
            if($inserted&&!$fired&&str_starts_with($sql,'SELECT RELEASE_LOCK(')&&str_contains($sql,'df-photo-producer-')){$fired=true;wp_get_current_user()->add_cap('manage_digiforge_orders',false);}return $sql;
        };
        add_filter('query',$revoke);
        try {$result=(new Shop2HeldJobRecovery())->enqueue('personalized_pod',$order,$intent,$prepared['current_evidence_hash'],$this->key($order,$intent,$prepared));}
        finally {remove_filter('query',$revoke);wp_get_current_user()->add_cap('manage_digiforge_orders',true);}
        self::assertTrue($fired);self::assertWPError($result);self::assertSame('COMMITTED',$result->get_error_data()['local_commit_state']);self::assertTrue($result->get_error_data()['local_metadata_committed']);
        $list=(new Shop2HeldJobRecovery())->forIntent('personalized_pod',$order,$intent);self::assertNotWPError($list);self::assertCount(1,$list['items']);self::assertSame('BLOCKED',$list['items'][0]['job']['state']);
    }

    /** @dataProvider lostEnqueueCommit */
    public function testUnknownEnqueueCommitCleansOriginalFrameBeforeReleasingPhotoLease(bool $durable):void
    {
        [$order,$intent]=$this->heldFixture();$prepared=$this->prepared($order,$intent);global $wpdb;$original=$wpdb;$original->flush();
        $lost=new class($original,$durable) extends wpdb {
            public bool $intercepted=false;
            public function __construct(wpdb $source,private bool $durable){foreach(get_object_vars($source)as $key=>$value)$this->$key=$value;$this->dbh=(new ReflectionProperty(wpdb::class,'dbh'))->getValue($source);}
            public function query($query){if($query==='COMMIT'&&!$this->intercepted){$this->intercepted=true;if($this->durable)parent::query($query);return false;}return parent::query($query);}
        };
        $wpdb=$lost;
        try {$result=(new Shop2HeldJobRecovery())->enqueue('personalized_pod',$order,$intent,$prepared['current_evidence_hash'],$this->key($order,$intent,$prepared));}
        finally {$lost->flush();$wpdb=$original;}
        self::assertTrue($lost->intercepted);self::assertWPError($result);self::assertSame('UNKNOWN',$result->get_error_data()['local_commit_state']);self::assertNull($result->get_error_data()['local_metadata_committed']);self::assertFalse($result->get_error_data()['replay_permitted']);
        self::assertSame('0',(string)$original->get_var('SELECT @@session.in_transaction'));
        $list=(new Shop2HeldJobRecovery())->forIntent('personalized_pod',$order,$intent);self::assertNotWPError($list);self::assertCount($durable?1:0,$list['items']);if($durable)self::assertSame('BLOCKED',$list['items'][0]['job']['state']);
        $name='df-photo-producer-'.substr(hash('sha256',$original->get_var('SELECT DATABASE()').'|'.$original->prefix),0,40);$other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->set_prefix($original->prefix);
        try{self::assertSame('1',(string)$other->get_var($other->prepare('SELECT GET_LOCK(%s,0)',$name)));}finally{$other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)',$name));$other->close();}
    }
    public static function lostEnqueueCommit():array{return ['durable lost acknowledgement'=>[true],'pending frame without acknowledgement'=>[false]];}

    public function testActorChangedByLastNativeCapabilityCheckCannotReceiveCommittedJobMetadata():void
    {
        [$order,$intent]=$this->heldFixture();$prepared=$this->prepared($order,$intent);$key=$this->key($order,$intent,$prepared);$actor=get_current_user_id();
        $released=false;$calls=0;
        $phase=static function(string $sql)use(&$released):string{if(str_starts_with($sql,'SELECT RELEASE_LOCK(')&&str_contains($sql,'df-photo-producer-'))$released=true;return $sql;};
        $count=static function(array $all,array $caps)use(&$released,&$calls):array{if($released&&in_array('manage_digiforge_pod',$caps,true))++$calls;return $all;};
        add_filter('query',$phase);add_filter('user_has_cap',$count,10,2);
        try {$job=(new Shop2HeldJobRecovery())->enqueue('personalized_pod',$order,$intent,$prepared['current_evidence_hash'],$key);}
        finally {remove_filter('query',$phase);remove_filter('user_has_cap',$count);}
        self::assertNotWPError($job);self::assertTrue($released);self::assertGreaterThan(0,$calls);
        $released=false;$seen=0;$fired=false;
        $change=static function(array $all,array $caps)use(&$released,&$seen,&$fired,$calls):array{
            if($released&&in_array('manage_digiforge_pod',$caps,true)&&++$seen===$calls){$fired=true;wp_set_current_user(0);}return $all;
        };
        add_filter('query',$phase);add_filter('user_has_cap',$change,10,2);
        try {$result=(new Shop2HeldJobRecovery())->enqueue('personalized_pod',$order,$intent,$prepared['current_evidence_hash'],$key);}
        finally {remove_filter('query',$phase);remove_filter('user_has_cap',$change);wp_set_current_user($actor);}
        self::assertTrue($fired);self::assertSame($calls,$seen);self::assertWPError($result);self::assertSame('COMMITTED',$result->get_error_data()['local_commit_state']);self::assertTrue($result->get_error_data()['local_metadata_committed']);self::assertFalse($result->get_error_data()['local_metadata_changed']);
        self::assertSame($job['job_id'],(new Shop2HeldJobRecovery())->forIntent('personalized_pod',$order,$intent)['items'][0]['job_id']);
    }

    public function testExpiredActualLeaseQuarantinesOnceAndCannotBeReenqueued():void
    {
        [$order,$intent]=$this->heldFixture();$job=$this->enqueue($order,$intent);$id=$job['job_id'];$this->start($id);$this->expire($id);
        $service=new Shop2HeldJobRecovery();$view=$service->inspect('personalized_pod',$order,$intent,$id);self::assertNotWPError($view);self::assertTrue($view['quarantine_eligible']);$before=$this->row($id);
        $after=$service->quarantine('personalized_pod',$order,$intent,$id,$view['snapshot_hash']);self::assertNotWPError($after);
        self::assertSame('HUMAN_REVIEW',$after['job']['state']);self::assertSame('LEASE_EXPIRED',$after['job']['last_error']);self::assertNull($this->row($id)['locked_by']);
        self::assertSame($before['payload'],$this->row($id)['payload']);self::assertSame($before['attempts'],$this->row($id)['attempts']);self::assertNull($this->row($id)['next_attempt_at']);
        self::assertWPError($service->quarantine('personalized_pod',$order,$intent,$id,$view['snapshot_hash']));
        $prepared=$this->prepared($order,$intent);self::assertWPError($service->enqueue('personalized_pod',$order,$intent,$prepared['current_evidence_hash'],$this->key($order,$intent,$prepared)));
        self::assertFalse($after['retry_permitted']);self::assertFalse($after['replay_permitted']);self::assertTrue(Settings::safety_locked());
    }

    public function testLiveLeaseWrongTupleAndDifferentSignedActorCannotMutateJob():void
    {
        [$order,$intent]=$this->heldFixture();$job=$this->enqueue($order,$intent);$id=$job['job_id'];$this->start($id);$before=$this->row($id);$service=new Shop2HeldJobRecovery();
        $view=$service->inspect('personalized_pod',$order,$intent,$id);self::assertNotWPError($view);self::assertFalse($view['quarantine_eligible']);
        self::assertWPError($service->quarantine('personalized_pod',$order,$intent,$id,$view['snapshot_hash']));
        self::assertWPError($service->inspect('digital',$order,$intent,$id));self::assertWPError($service->inspect('personalized_pod',$order,$intent+100,$id));
        PortalAccessFixture::login($this->fixture->reviewer);self::assertWPError($service->inspect('personalized_pod',$order,$intent,$id));self::assertSame($before,$this->row($id));
        wp_set_current_user(0);$reads=[];$capture=static function(string $sql)use(&$reads):string{if(str_starts_with($sql,'SELECT ')&&str_contains($sql,Tables::jobs()))$reads[]=$sql;return $sql;};add_filter('query',$capture);
        try{self::assertWPError($service->inspect('personalized_pod',$order,$intent,$id));}finally{remove_filter('query',$capture);}self::assertSame([],$reads);
    }

    public function testChangedSnapshotAndMalformedUnknownJobBlockFreshEnqueue():void
    {
        [$order,$intent]=$this->heldFixture();$job=$this->enqueue($order,$intent);$id=$job['job_id'];$this->start($id);$this->expire($id);$service=new Shop2HeldJobRecovery();$view=$service->inspect('personalized_pod',$order,$intent,$id);
        global $wpdb;$wpdb->update(Tables::jobs(),['locked_by'=>'changed_owner'],['id'=>$id]);$changed=$this->row($id);
        self::assertWPError($service->quarantine('personalized_pod',$order,$intent,$id,$view['snapshot_hash']));self::assertSame($changed,$this->row($id));
        $wpdb->update(Tables::jobs(),['payload'=>'{broken'],['id'=>$id]);self::assertWPError($service->inspect('personalized_pod',$order,$intent,$id));
        $prepared=$this->prepared($order,$intent);self::assertWPError($service->enqueue('personalized_pod',$order,$intent,$prepared['current_evidence_hash'],$this->key($order,$intent,$prepared)));
        self::assertSame('{broken',$this->row($id)['payload']);self::assertSame('RUNNING',$this->row($id)['state']);
    }

    public function testExplicitNullDependenciesCannotMasqueradeAsReadyEvidence():void
    {
        [$order,$intent]=$this->heldFixture();$job=$this->enqueue($order,$intent);$id=$job['job_id'];
        $payload=json_decode($this->row($id)['payload'],true);$payload['_digiforge_dependencies']=null;
        global $wpdb;self::assertSame(1,$wpdb->update(Tables::jobs(),['payload'=>wp_json_encode($payload)],['id'=>$id]));
        $repository=new JobRepository();self::assertTrue($repository->transition($id,'BLOCKED','QUEUED'));
        self::assertFalse($repository->acquireLease($id,'null_dependency_worker'));self::assertFalse($repository->start($id,'null_dependency_worker'));
        $before=$this->row($id);$service=new Shop2HeldJobRecovery();
        self::assertWPError($service->inspect('personalized_pod',$order,$intent,$id));
        self::assertWPError($service->forIntent('personalized_pod',$order,$intent));
        self::assertSame($before,$this->row($id));self::assertNull($before['locked_by']);
    }

    public function testQuarantineDoesNotRequireOrCertifyCurrentPlanApproval():void
    {
        [$order,$intent,$plan]=$this->heldFixture();$job=$this->enqueue($order,$intent);$id=$job['job_id'];$this->start($id);$this->expire($id);
        self::assertNotWPError((new Repository())->transition('plan',$plan,'ON_HOLD'));
        self::assertWPError((new HeldFulfillmentPreparation())->prepare('personalized_pod',$order,$intent));
        $service=new Shop2HeldJobRecovery();$view=$service->inspect('personalized_pod',$order,$intent,$id);self::assertNotWPError($view);
        self::assertNotWPError($service->quarantine('personalized_pod',$order,$intent,$id,$view['snapshot_hash']));
        global $wpdb;self::assertSame('ON_HOLD',$wpdb->get_var($wpdb->prepare('SELECT state FROM '.Tables::fulfillment_plans().' WHERE id=%d',$plan)));
    }

    public function testOuterTransactionAndFailedAuditPreserveExpiredJob():void
    {
        [$order,$intent]=$this->heldFixture();$job=$this->enqueue($order,$intent);$id=$job['job_id'];$this->start($id);$this->expire($id);$service=new Shop2HeldJobRecovery();$view=$service->inspect('personalized_pod',$order,$intent,$id);$before=$this->row($id);global $wpdb;
        $wpdb->query('START TRANSACTION');try{self::assertWPError($service->quarantine('personalized_pod',$order,$intent,$id,$view['snapshot_hash']));self::assertSame('1',(string)$wpdb->get_var('SELECT @@session.in_transaction'));}finally{$wpdb->query('ROLLBACK');}
        $deny=static fn(string $sql):string=>str_starts_with($sql,'INSERT INTO `'.Tables::audit_log().'`')?'INSERT INTO missing_held_recovery_audit VALUES (1)':$sql;
        $previous=$wpdb->suppress_errors(true);add_filter('query',$deny);
        try{self::assertWPError($service->quarantine('personalized_pod',$order,$intent,$id,$view['snapshot_hash']));}finally{remove_filter('query',$deny);$wpdb->suppress_errors($previous);}
        self::assertSame($before,$this->row($id));
    }

    /** @dataProvider unexpectedDefaults */
    public function testUnexpectedNewJobDefaultsCannotBeAccepted(string $column,mixed $value):void
    {
        [$order,$intent]=$this->heldFixture();$p=$this->prepared($order,$intent);$inventories=0;$changed=false;
        $change=static function(string $sql)use(&$inventories,&$changed,$column,$value):string{
            if(str_starts_with($sql,'SELECT * FROM '.Tables::jobs().' WHERE job_type=')&&++$inventories===2){
                global $wpdb;$id=(int)$wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM '.Tables::jobs().' WHERE job_type=%s','shop2_held_preparation'));
                $bad=$column==='idempotency_key'?'corrupt-key-'.$id:$value;
                $changed=$wpdb->update(Tables::jobs(),[$column=>$bad],['id'=>$id])===1;
            }
            return $sql;
        };
        add_filter('query',$change);
        try {$result=(new Shop2HeldJobRecovery())->enqueue('personalized_pod',$order,$intent,$p['current_evidence_hash'],$this->key($order,$intent,$p));}
        finally {remove_filter('query',$change);}
        self::assertTrue($changed);self::assertWPError($result);
        $jobs=(new Shop2HeldJobRecovery())->forIntent('personalized_pod',$order,$intent);self::assertNotWPError($jobs);self::assertSame([],$jobs['items']);
    }
    public static function unexpectedDefaults():array{return [['max_attempts',99],['idempotency_key','corrupt-key'],['locked_at','2000-01-01 00:00:00'],['last_error','unexpected'],['dead_lettered_at','2000-01-01 00:00:00']];}

    public function testConcurrentLineChangeCannotCommitAnApparentlyCurrentStaleEnqueue():void
    {
        [$order,$intent]=$this->heldFixture();$p=$this->prepared($order,$intent);global $wpdb;
        $line=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Tables::order_line_items().' WHERE order_id=%d',$order));
        $other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->suppress_errors(true);$other->query('SET SESSION innodb_lock_wait_timeout=1');
        $attempted=false;$write=null;$writeError='';
        $change=static function(string $sql)use($other,$line,&$attempted,&$write,&$writeError):string{
            if(!$attempted&&str_starts_with($sql,'SELECT li.id,li.listing_id,li.product_version_id')){
                $attempted=true;$write=$other->update(Tables::order_line_items(),['quantity'=>3],['id'=>$line]);$writeError=$other->last_error;
            }
            return $sql;
        };
        add_filter('query',$change);
        try {$result=(new Shop2HeldJobRecovery())->enqueue('personalized_pod',$order,$intent,$p['current_evidence_hash'],$this->key($order,$intent,$p));}
        finally {remove_filter('query',$change);$other->close();}
        self::assertTrue($attempted);
        $quantity=(int)$wpdb->get_var($wpdb->prepare('SELECT quantity FROM '.Tables::order_line_items().' WHERE id=%d',$line));
        if($write===false){self::assertStringContainsString('Lock wait timeout',$writeError);self::assertSame(2,$quantity);self::assertNotWPError($result);}
        else {self::assertSame(1,$write);self::assertSame(3,$quantity);self::assertWPError($result,'A committed concurrent source change cannot be hidden by a repeatable-read snapshot.');}
    }

    /** @dataProvider mutationKinds */
    public function testModuleRevocationDuringCommitCannotReturnMutationSuccess(string $action):void
    {
        [$order,$intent]=$this->heldFixture();$p=$this->prepared($order,$intent);$service=new Shop2HeldJobRecovery();$job=null;
        if($action==='quarantine'){$job=$this->enqueue($order,$intent);$this->start($job['job_id']);$this->expire($job['job_id']);$view=$service->inspect('personalized_pod',$order,$intent,$job['job_id']);}
        $revoked=false;$revoke=static function(string $sql)use(&$revoked):string{if(!$revoked&&$sql==='COMMIT'){$revoked=true;wp_get_current_user()->add_cap('manage_digiforge_automation',false);}return $sql;};add_filter('query',$revoke);
        try {$result=$action==='enqueue'?$service->enqueue('personalized_pod',$order,$intent,$p['current_evidence_hash'],$this->key($order,$intent,$p)):$service->quarantine('personalized_pod',$order,$intent,$job['job_id'],$view['snapshot_hash']);}
        finally{remove_filter('query',$revoke);}
        self::assertTrue($revoked);self::assertWPError($result);self::assertSame('COMMITTED',$result->get_error_data()['local_commit_state']);self::assertTrue($result->get_error_data()['local_metadata_committed']);self::assertTrue($result->get_error_data()['local_metadata_changed']);self::assertFalse($result->get_error_data()['replay_permitted']);
        wp_get_current_user()->add_cap('manage_digiforge_automation',true);$list=$service->forIntent('personalized_pod',$order,$intent);self::assertNotWPError($list);self::assertCount(1,$list['items']);self::assertSame($action==='enqueue'?'BLOCKED':'HUMAN_REVIEW',$list['items'][0]['job']['state']);
    }
    public static function mutationKinds():array{return [['enqueue'],['quarantine']];}

    public function testSeparatePhpWorkerStartsActualHeldJobAndCrashesBeforeCompletion():void
    {
        self::assertFileExists(__DIR__.'/fixtures/shop2-held-recovery-worker.php');
        [$order,$intent]=$this->heldFixture();$job=$this->enqueue($order,$intent);$id=$job['job_id'];
        $this->worker('START_EXIT',$order,$intent,$id,$job['snapshot_hash'],73);
        $row=$this->row($id);self::assertSame('RUNNING',$row['state']);self::assertSame('disposable_child',$row['locked_by']);
        $this->expire($id);$service=new Shop2HeldJobRecovery();$view=$service->inspect('personalized_pod',$order,$intent,$id);self::assertNotWPError($view);
        $result=$service->quarantine('personalized_pod',$order,$intent,$id,$view['snapshot_hash']);self::assertNotWPError($result);self::assertSame('HUMAN_REVIEW',$result['job']['state']);self::assertFalse($result['read_only']);self::assertTrue($result['local_metadata_changed']);
        self::assertWPError($service->quarantine('personalized_pod',$order,$intent,$id,$view['snapshot_hash']));
    }

    public function testSeparatePhpWorkerLostCommitAcknowledgementLeavesQuarantineConsumed():void
    {
        self::assertFileExists(__DIR__.'/fixtures/shop2-held-recovery-worker.php');
        [$order,$intent]=$this->heldFixture();$job=$this->enqueue($order,$intent);$id=$job['job_id'];$this->start($id);$this->expire($id);$service=new Shop2HeldJobRecovery();$view=$service->inspect('personalized_pod',$order,$intent,$id);
        $this->worker('QUARANTINE_AFTER_COMMIT',$order,$intent,$id,$view['snapshot_hash'],74);
        $fresh=$service->inspect('personalized_pod',$order,$intent,$id);self::assertNotWPError($fresh);self::assertSame('HUMAN_REVIEW',$fresh['job']['state']);self::assertFalse($fresh['quarantine_eligible']);
        self::assertWPError($service->quarantine('personalized_pod',$order,$intent,$id,$view['snapshot_hash']));$p=$this->prepared($order,$intent);self::assertWPError($service->enqueue('personalized_pod',$order,$intent,$p['current_evidence_hash'],$this->key($order,$intent,$p)));
    }

    public function testDurableCommitWithFalseDriverResultIsExplicitlyUnknownAndCannotReplay():void
    {
        [$order,$intent]=$this->heldFixture();$job=$this->enqueue($order,$intent);$id=$job['job_id'];$this->start($id);$this->expire($id);
        $service=new Shop2HeldJobRecovery();$view=$service->inspect('personalized_pod',$order,$intent,$id);self::assertNotWPError($view);
        global $wpdb;$original=$wpdb;$original->flush();
        $lost=new class($original) extends wpdb {
            public bool $commitExecuted=false;
            public function __construct(wpdb $source){foreach(get_object_vars($source)as $key=>$value)$this->$key=$value;$this->dbh=(new ReflectionProperty(wpdb::class,'dbh'))->getValue($source);}
            public function query($query){$result=parent::query($query);if($query==='COMMIT'&&$result!==false&&!$this->commitExecuted){$this->commitExecuted=true;return false;}return $result;}
        };
        $wpdb=$lost;
        try {$result=$service->quarantine('personalized_pod',$order,$intent,$id,$view['snapshot_hash']);}
        finally {$lost->flush();$wpdb=$original;}
        self::assertTrue($lost->commitExecuted);self::assertWPError($result);
        $fresh=$service->inspect('personalized_pod',$order,$intent,$id);self::assertNotWPError($fresh);self::assertSame('HUMAN_REVIEW',$fresh['job']['state']);
        $replay=$service->quarantine('personalized_pod',$order,$intent,$id,$view['snapshot_hash']);self::assertWPError($replay);self::assertSame('NOT_ATTEMPTED',$replay->get_error_data()['local_commit_state']);self::assertFalse($replay->get_error_data()['local_metadata_committed']);
        $data=$result->get_error_data();self::assertSame('UNKNOWN',$data['local_commit_state']??null,(string)wp_json_encode($data));self::assertNull($data['local_metadata_committed']);self::assertNull($data['local_metadata_changed']);self::assertTrue($data['persisted_effect_possible']);self::assertFalse($data['replay_permitted']);
    }

    private function worker(string $action,int $order,int $intent,int $job,string $snapshot,int $expectedExit):void
    {
        global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');
        $settingsBefore=$wpdb->get_results('SELECT * FROM '.Tables::settings().' ORDER BY setting_key',ARRAY_A);
        self::assertTrue(Settings::get('stop_all'));self::assertFalse(Settings::get('automation_armed'));self::assertTrue(Settings::safety_locked());
        foreach(DigiForge\Core\Config::SWITCHES as $switch)self::assertFalse(Settings::is_enabled($switch));
        $dir=sys_get_temp_dir().'/df-held-job-worker-'.wp_generate_uuid4();self::assertTrue(mkdir($dir,0700));
        $fixture=['synthetic_only'=>true,'autoload'=>dirname((new ReflectionClass(Composer\Autoload\ClassLoader::class))->getFileName(),2).'/autoload.php','actor'=>get_current_user_id(),'cookies'=>$_COOKIE,'trust_key'=>DIGIFORGE_SHOP_ACCESS_TRUST_KEY,'authority_file'=>DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE,'config'=>defined('WP_TESTS_CONFIG_FILE_PATH')?WP_TESTS_CONFIG_FILE_PATH:null,'action'=>$action,'order'=>$order,'intent'=>$intent,'job'=>$job,'snapshot'=>$snapshot];
        file_put_contents($dir.'/fixture.json',wp_json_encode($fixture));chmod($dir.'/fixture.json',0600);$process=null;
        try {
            $process=proc_open([PHP_BINARY,__DIR__.'/fixtures/shop2-held-recovery-worker.php',$dir.'/fixture.json',$dir.'/result'],[0=>['file','/dev/null','r'],1=>['file',$dir.'/log','w'],2=>['file',$dir.'/err','w']],$pipes);self::assertIsResource($process);
            $until=microtime(true)+45;do{$status=proc_get_status($process);if(!$status['running'])break;usleep(10000);}while(microtime(true)<$until);
            if($status['running']){proc_terminate($process);self::fail('Disposable held worker exceeded its bounded deadline.');}
            $exit=$status['exitcode'];proc_close($process);$process=null;self::assertSame($expectedExit,$exit,(string)file_get_contents($dir.'/err').' / '.(is_file($dir.'/result')?file_get_contents($dir.'/result'):''));
            self::assertSame($settingsBefore,$wpdb->get_results('SELECT * FROM '.Tables::settings().' ORDER BY setting_key',ARRAY_A),'Worker must preserve actual persisted settings before cleanup resets anything.');
            self::assertTrue(Settings::get('stop_all'));self::assertFalse(Settings::get('automation_armed'));self::assertTrue(Settings::safety_locked());foreach(DigiForge\Core\Config::SWITCHES as $switch)self::assertFalse(Settings::is_enabled($switch));
        }finally{if(is_resource($process)){proc_terminate($process);proc_close($process);}foreach(glob($dir.'/*')as $path)unlink($path);rmdir($dir);}
    }

    private function enqueue(int $order,int $intent):array
    {
        $prepared=$this->prepared($order,$intent);$result=(new Shop2HeldJobRecovery())->enqueue('personalized_pod',$order,$intent,$prepared['current_evidence_hash'],$this->key($order,$intent,$prepared));self::assertNotWPError($result);return $result;
    }
    private function prepared(int $order,int $intent):array{$p=(new HeldFulfillmentPreparation())->prepare('personalized_pod',$order,$intent);self::assertNotWPError($p);return $p;}
    private function key(int $order,int $intent,array $p):string{return Shop2HeldJobRecovery::enqueueKey('personalized_pod',$order,$intent,$p['plan_payload_hash'],$p['current_evidence_hash'],get_current_user_id());}
    private function row(int $id):array{global $wpdb;return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::jobs().' WHERE id=%d',$id),ARRAY_A);}
    private function start(int $id):void{$r=new JobRepository();self::assertTrue($r->transition($id,'BLOCKED','QUEUED'));self::assertTrue($r->acquireLease($id,'actual_held_worker'));self::assertTrue($r->start($id,'actual_held_worker'));}
    private function expire(int $id):void{global $wpdb;self::assertSame(1,$wpdb->update(Tables::jobs(),['lease_expires_at'=>gmdate('Y-m-d H:i:s',time()-120)],['id'=>$id]));}
    private function heldFixture():array
    {
        [,$mapping,$version]=$this->product();$r=new Repository();$o=PrintifyApiApprovalFixture::checked($r->createOrder(['channel'=>'etsy','shop_reference'=>'personalized_pod','environment'=>'test','currency'=>'USD']));PrintifyApiApprovalFixture::checked($r->addLineItem(['order_id'=>$o['id'],'product_version_id'=>$version,'provider_mapping_id'=>$mapping,'quantity'=>2]));
        foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED']as $state)PrintifyApiApprovalFixture::checked($r->transition('order',(int)$o['id'],$state));
        $p=PrintifyApiApprovalFixture::checked($r->createPlan(['order_id'=>$o['id'],'provider'=>'printify','shipping_method_metadata'=>['method'=>'standard'],'cost_snapshot_metadata'=>['base_production_cost'=>10,'shipping_estimate'=>4]]));foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED']as $state)PrintifyApiApprovalFixture::checked($r->transition('plan',(int)$p['id'],$state));
        $i=PrintifyApiApprovalFixture::checked($r->createIntent(['order_id'=>$o['id'],'fulfillment_plan_id'=>$p['id'],'intent_type'=>'PREPARE_FULFILLMENT']));return [(int)$o['id'],(int)$i['id'],(int)$p['id']];
    }
}
