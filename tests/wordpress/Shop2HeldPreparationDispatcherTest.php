<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/NativeBusinessOrderFixture.php';

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Orders\HeldFulfillmentPreparation;
use DigiForge\Orders\Repository;
use DigiForge\Portal\ScopedCapabilityPolicyRepository;
use DigiForge\Queue\JobRepository;
use DigiForge\Queue\Shop2HeldPreparationDispatcher;

/** Local metadata execution only. No native/provider authority or external transport is substituted. */
final class Shop2HeldPreparationDispatcherTest extends WP_UnitTestCase
{
    use NativeBusinessOrderFixture;
    private array $queueSnapshot=[];
    private array $intentSnapshot=[];
    private array $policySnapshot=[];
    private int $httpCalls=0;
    private $denyHttp;

    public function setUp():void
    {
        parent::setUp();
        self::assertTrue(class_exists(Shop2HeldPreparationDispatcher::class),'The bounded local held dispatcher is missing.');
        DigiForge\Core\Activator::activate();
        global $wpdb;
        $this->queueSnapshot=$wpdb->get_results('SELECT * FROM '.Tables::jobs(),ARRAY_A);
        $this->intentSnapshot=$wpdb->get_results('SELECT * FROM '.Tables::fulfillment_intents(),ARRAY_A);
        $this->policySnapshot=$wpdb->get_results('SELECT * FROM '.Tables::scoped_capability_policies(),ARRAY_A);
        $this->bootOrderFixture();
        $wpdb->query($wpdb->prepare('DELETE FROM '.Tables::scoped_capability_policies()." WHERE shop_key=%s AND workflow_key IN ('shop','held_preparation') AND capability=%s",'personalized_pod','order_automation'));
        Settings::protectProduction();
        $this->denyHttp=function(){ $this->httpCalls++;return new WP_Error('unexpected_http'); };
        add_filter('pre_http_request',$this->denyHttp);
    }

    public function tearDown():void
    {
        if($this->denyHttp!==null)remove_filter('pre_http_request',$this->denyHttp);
        try {
            if(isset($this->fixture)){
                $this->restoreOrderFixture();
                global $wpdb;
                foreach([Tables::jobs()=>$this->queueSnapshot,Tables::fulfillment_intents()=>$this->intentSnapshot,Tables::scoped_capability_policies()=>$this->policySnapshot] as $table=>$rows){
                    $wpdb->query('DELETE FROM '.$table);
                    foreach($rows as $row)$wpdb->insert($table,$row,array_fill(0,count($row),'%s'));
                }
                self::assertTrue(Settings::protectProduction());
            }
            self::assertSame(0,$this->httpCalls,'Local dispatch must never call HTTP.');
        } finally {parent::tearDown();}
    }

    public function testDefaultDisabledDispatcherDoesNotClaimApprovedLocalJob():void
    {
        [$order,$intent]=$this->heldFixture();
        $service=new Shop2HeldPreparationDispatcher();
        $job=$service->enqueue('personalized_pod',$order,$intent,'disabled');
        self::assertIsInt($job);
        self::assertWPError($service->dispatch($job));
        $stored=$this->job($job);
        self::assertSame('BLOCKED',$stored['state']);
        self::assertNull($stored['locked_by']);
        self::assertNull($stored['lease_expires_at']);
        self::assertTrue(Settings::get('stop_all'));
        self::assertFalse(Settings::get('automation_armed'));
    }

    public function testActualCurrentActorDispatcherCompletesOnlyLocalHeldPreparation():void
    {
        [$order,$intent]=$this->heldFixture();
        $prepared=(new HeldFulfillmentPreparation())->prepare('personalized_pod',$order,$intent);
        self::assertNotWPError($prepared);
        $service=new Shop2HeldPreparationDispatcher();
        $job=$service->enqueue('personalized_pod',$order,$intent,'positive');
        self::assertIsInt($job);
        $this->enableDisposableLocalDispatch();
        $actor=get_current_user_id();
        $result=$service->dispatch($job);
        self::assertNotWPError($result);
        self::assertSame('SUCCESS',$result['job_state']);
        self::assertSame('HELD_FULFILLMENT_PREPARED',$result['result']['state']);
        self::assertSame($prepared,$result['result']);
        self::assertFalse($result['external_execution_authorized']);
        self::assertFalse($result['result']['preparation']['adapter_invoked']);
        self::assertTrue($result['release_hold']);
        self::assertSame($actor,get_current_user_id());
        $stored=$this->job($job);
        self::assertSame('SUCCESS',$stored['state']);
        self::assertNull($stored['locked_by']);
        self::assertNotNull($stored['completed_at']);
        self::assertSame(0,(int)$stored['attempts']);
        self::assertWPError($service->dispatch($job),'A completed job cannot be dispatched twice.');
        global $wpdb;
        self::assertSame('BLOCKED',$wpdb->get_var($wpdb->prepare('SELECT state FROM '.Tables::fulfillment_intents().' WHERE id=%d',$intent)));
    }

    public function testAnonymousAndDifferentCurrentActorCannotClaimPinnedJob():void
    {
        [$order,$intent]=$this->heldFixture();$service=new Shop2HeldPreparationDispatcher();
        $job=$service->enqueue('personalized_pod',$order,$intent,'actor');self::assertIsInt($job);
        $this->enableDisposableLocalDispatch();
        wp_set_current_user(0);self::assertWPError($service->dispatch($job));self::assertSame(0,get_current_user_id());
        PortalAccessFixture::login($this->fixture->reviewer);
        self::assertWPError($service->dispatch($job));self::assertSame($this->fixture->reviewer,get_current_user_id());
        self::assertSame('BLOCKED',$this->job($job)['state']);self::assertNull($this->job($job)['locked_by']);
    }

    public function testStopAllAndAutomationOffRemainRuntimeVetoes():void
    {
        [$order,$intent]=$this->heldFixture();$service=new Shop2HeldPreparationDispatcher();
        $job=$service->enqueue('personalized_pod',$order,$intent,'runtime-veto');self::assertIsInt($job);
        foreach(['stop_all'=>true,'automation_armed'=>false,'order_automation_activation_authorized'=>false] as $key=>$value){
            $this->enableDisposableLocalDispatch();$this->fixture->setting($key,$value);
            self::assertWPError($service->dispatch($job),$key);
            self::assertSame('BLOCKED',$this->job($job)['state']);self::assertNull($this->job($job)['locked_by']);
        }
    }

    public function testChangedApprovedEvidenceRequiresHumanReviewWithoutRetry():void
    {
        [$order,$intent,,$line]=$this->heldFixture();$service=new Shop2HeldPreparationDispatcher();
        $job=$service->enqueue('personalized_pod',$order,$intent,'stale');self::assertIsInt($job);
        global $wpdb;self::assertSame(1,$wpdb->update(Tables::order_line_items(),['quantity'=>3],['id'=>$line]));
        $this->enableDisposableLocalDispatch();self::assertWPError($service->dispatch($job));
        $stored=$this->job($job);self::assertSame('HUMAN_REVIEW',$stored['state']);self::assertNull($stored['locked_by']);
        self::assertNull($stored['next_attempt_at']);self::assertSame(0,(int)$stored['attempts']);
    }

    public function testDependenciesAndExistingLeaseUseRealRepositoryAdmission():void
    {
        [$order,$intent]=$this->heldFixture();$repo=new JobRepository();
        $dependency=$repo->enqueue('synthetic_local_dependency');self::assertIsInt($dependency);
        $service=new Shop2HeldPreparationDispatcher();$job=$service->enqueue('personalized_pod',$order,$intent,'dependencies',[$dependency]);self::assertIsInt($job);
        $this->enableDisposableLocalDispatch();self::assertWPError($service->dispatch($job));
        self::assertNull($this->job($job)['locked_by']);
        self::assertTrue($repo->transition($dependency,'BLOCKED','QUEUED'));
        self::assertTrue($repo->acquireLease($dependency,'synthetic_dependency'));
        self::assertTrue($repo->start($dependency,'synthetic_dependency'));
        self::assertTrue($repo->transition($dependency,'RUNNING','SUCCESS'));
        self::assertTrue($repo->releaseLease($dependency,'synthetic_dependency'));
        self::assertTrue($repo->acquireLease($job,'other_worker'));
        self::assertWPError($service->dispatch($job));self::assertSame('other_worker',$this->job($job)['locked_by']);
        self::assertTrue($repo->releaseLease($job,'other_worker'));
        self::assertNotWPError($service->dispatch($job));
    }

    public function testPayloadAndShopCannotBeReinterpreted():void
    {
        [$order,$intent]=$this->heldFixture();$service=new Shop2HeldPreparationDispatcher();
        self::assertWPError($service->enqueue('digital',$order,$intent,'wrong-shop'));
        $job=$service->enqueue('personalized_pod',$order,$intent,'payload');self::assertIsInt($job);
        global $wpdb;$stored=$this->job($job);$payload=json_decode($stored['payload'],true);$payload['external_execution_authorized']=true;
        self::assertSame(1,$wpdb->update(Tables::jobs(),['payload'=>wp_json_encode($payload)],['id'=>$job]));
        $this->enableDisposableLocalDispatch();self::assertWPError($service->dispatch($job));
        self::assertSame('BLOCKED',$this->job($job)['state']);self::assertNull($this->job($job)['locked_by']);
    }

    /** @dataProvider completionRaces */
    public function testCompletionCannotOverwriteChangedClaim(string $column,string $value):void
    {
        [$order,$intent]=$this->heldFixture();$service=new Shop2HeldPreparationDispatcher();
        $job=$service->enqueue('personalized_pod',$order,$intent,'owner-fence');self::assertIsInt($job);
        $this->enableDisposableLocalDispatch();$changed=false;$field=$column==='locked_by_case'?'locked_by':$column;$changedValue=$value;
        $change=function(string $sql)use($job,$column,$field,$value,&$changedValue,&$changed):string{
            if(!$changed&&str_starts_with($sql,'SELECT * FROM '.Tables::fulfillment_intents())){
                $changed=true;global $wpdb;
                $changedValue=$column==='locked_by_case'?strtoupper($this->job($job)['locked_by']):$value;
                $wpdb->update(Tables::jobs(),[$field=>$changedValue],['id'=>$job,'state'=>'RUNNING']);
            }
            return $sql;
        };
        add_filter('query',$change);
        try {self::assertWPError($service->dispatch($job));}
        finally {remove_filter('query',$change);}
        self::assertTrue($changed);self::assertSame('RUNNING',$this->job($job)['state']);self::assertSame($changedValue,$this->job($job)[$field]);
    }

    public static function completionRaces():array
    {
        return [['locked_by','different_worker'],['lease_expires_at','2000-01-01 00:00:00'],['payload','{}'],['locked_by_case','']];
    }

    public function testStopAllRecheckedAfterHandlerCannotRecordSuccess():void
    {
        [$order,$intent]=$this->heldFixture();$service=new Shop2HeldPreparationDispatcher();
        $job=$service->enqueue('personalized_pod',$order,$intent,'late-stop');self::assertIsInt($job);
        $this->enableDisposableLocalDispatch();$changed=false;
        $stop=function(string $sql)use(&$changed):string{
            if(!$changed&&str_starts_with($sql,'SELECT * FROM '.Tables::fulfillment_intents())){$changed=true;$this->fixture->setting('stop_all',true);}
            return $sql;
        };
        add_filter('query',$stop);
        try {self::assertWPError($service->dispatch($job));}
        finally {remove_filter('query',$stop);}
        self::assertTrue($changed);self::assertSame('HUMAN_REVIEW',$this->job($job)['state']);self::assertNull($this->job($job)['locked_by']);
    }

    public function testCurrentActorCannotChangeDuringLocalHandler():void
    {
        [$order,$intent]=$this->heldFixture();$service=new Shop2HeldPreparationDispatcher();
        $job=$service->enqueue('personalized_pod',$order,$intent,'late-actor');self::assertIsInt($job);
        $this->enableDisposableLocalDispatch();$changed=false;
        $change=function(string $sql)use(&$changed):string{
            if(!$changed&&str_starts_with($sql,'SELECT * FROM '.Tables::fulfillment_intents())){$changed=true;PortalAccessFixture::login($this->fixture->reviewer);}
            return $sql;
        };
        add_filter('query',$change);
        try {self::assertWPError($service->dispatch($job));}
        finally {remove_filter('query',$change);}
        self::assertTrue($changed);self::assertSame('HUMAN_REVIEW',$this->job($job)['state']);self::assertSame($this->fixture->reviewer,get_current_user_id());
    }

    /** @dataProvider deniedRestrictions */
    public function testMissingOrDisabledScopedRestrictionDeniesBeforeClaim(?bool $shop,?bool $workflow):void
    {
        [$order,$intent]=$this->heldFixture();$service=new Shop2HeldPreparationDispatcher();
        $job=$service->enqueue('personalized_pod',$order,$intent,'restricted');self::assertIsInt($job);
        $this->enableDisposableLocalDispatch(false);
        if($shop!==null)$this->appendRestriction('shop',$shop);
        if($workflow!==null)$this->appendRestriction('held_preparation',$workflow);
        self::assertWPError($service->dispatch($job));
        self::assertSame('BLOCKED',$this->job($job)['state']);self::assertNull($this->job($job)['locked_by']);
    }

    public static function deniedRestrictions():array
    {
        return [[null,null],[true,null],[null,true],[false,true],[true,false]];
    }

    public function testOtherWorkflowEvidenceCannotReplaceHeldPreparationRestriction():void
    {
        [$order,$intent]=$this->heldFixture();$service=new Shop2HeldPreparationDispatcher();
        $job=$service->enqueue('personalized_pod',$order,$intent,'other-workflow');self::assertIsInt($job);
        $this->enableDisposableLocalDispatch(false);$this->appendRestriction('shop',true);$this->appendRestriction('unrelated_workflow',true);
        self::assertWPError($service->dispatch($job));self::assertSame('BLOCKED',$this->job($job)['state']);
    }

    public function testMalformedScopedRestrictionDeniesBeforeClaim():void
    {
        [$order,$intent]=$this->heldFixture();$service=new Shop2HeldPreparationDispatcher();
        $job=$service->enqueue('personalized_pod',$order,$intent,'malformed-restriction');self::assertIsInt($job);
        $this->enableDisposableLocalDispatch(false);$this->appendRestriction('shop',true);$record=$this->appendRestriction('held_preparation',true);
        global $wpdb;self::assertSame(1,$wpdb->update(Tables::scoped_capability_policies(),['enabled'=>0],['id'=>$record['id']]));
        self::assertWPError($service->dispatch($job));self::assertSame('BLOCKED',$this->job($job)['state']);self::assertNull($this->job($job)['locked_by']);
    }

    public function testUnavailableScopedRestrictionDeniesBeforeClaim():void
    {
        [$order,$intent]=$this->heldFixture();$service=new Shop2HeldPreparationDispatcher();
        $job=$service->enqueue('personalized_pod',$order,$intent,'unavailable-restriction');self::assertIsInt($job);
        $this->enableDisposableLocalDispatch();
        $deny=static fn(string $sql):string=>str_starts_with($sql,'SELECT ')&&str_contains($sql,Tables::scoped_capability_policies())?'SELECT FROM /* synthetic policy read failure */':$sql;
        global $wpdb;$previous=$wpdb->suppress_errors(true);add_filter('query',$deny);
        try {self::assertWPError($service->dispatch($job));}
        finally {remove_filter('query',$deny);$wpdb->suppress_errors($previous);}
        self::assertSame('BLOCKED',$this->job($job)['state']);self::assertNull($this->job($job)['locked_by']);
    }

    public function testScopedDisableDuringHandlerCannotRecordSuccess():void
    {
        [$order,$intent]=$this->heldFixture();$service=new Shop2HeldPreparationDispatcher();
        $job=$service->enqueue('personalized_pod',$order,$intent,'late-restriction');self::assertIsInt($job);
        $this->enableDisposableLocalDispatch();$changed=false;
        $disable=function(string $sql)use(&$changed):string{
            if(!$changed&&str_starts_with($sql,'SELECT * FROM '.Tables::fulfillment_intents())){$changed=true;$this->appendRestriction('held_preparation',false);}
            return $sql;
        };
        add_filter('query',$disable);
        try {self::assertWPError($service->dispatch($job));}
        finally {remove_filter('query',$disable);}
        self::assertTrue($changed);self::assertSame('HUMAN_REVIEW',$this->job($job)['state']);self::assertNull($this->job($job)['locked_by']);
    }

    public function testSignedActorWithoutAutomationModuleCannotReadJobOrRestrictionEvidence():void
    {
        [$order,$intent]=$this->heldFixture();$service=new Shop2HeldPreparationDispatcher();
        $job=$service->enqueue('personalized_pod',$order,$intent,'module-read-order');self::assertIsInt($job);
        $this->enableDisposableLocalDispatch();
        // Real signed Shop 2 delegation remains, but this actor's module right is revoked.
        get_userdata($this->fixture->reviewer)->add_cap('manage_digiforge_automation',false);
        PortalAccessFixture::login($this->fixture->reviewer);
        self::assertTrue(is_user_logged_in());self::assertFalse(current_user_can('manage_digiforge_automation'));
        $businessReads=[];
        $observe=static function(string $sql)use(&$businessReads):string{
            if(str_starts_with($sql,'SELECT ')&&(str_contains($sql,Tables::jobs())||str_contains($sql,Tables::scoped_capability_policies())))$businessReads[]=$sql;
            return $sql;
        };
        add_filter('query',$observe);
        try {self::assertWPError($service->dispatch($job));}
        finally {remove_filter('query',$observe);}
        self::assertSame([],$businessReads,'Unassigned module access must deny before job or scoped-policy reads.');
        self::assertSame('BLOCKED',$this->job($job)['state']);self::assertNull($this->job($job)['locked_by']);
    }

    /** Disposable test rows only; never invokes a production release transition. */
    private function enableDisposableLocalDispatch(bool $restrictions=true):void
    {
        foreach(['activation_authorized'=>true,'automation_armed'=>true,'stop_all'=>false,'order_automation'=>true,'order_automation_activation_authorized'=>true] as $key=>$value)$this->fixture->setting($key,$value);
        self::assertTrue(Settings::is_enabled('order_automation'));
        if($restrictions){$this->appendRestriction('shop',true);$this->appendRestriction('held_preparation',true);}
    }

    /** Stored restriction evidence cannot grant global, actor, native or external authority. */
    private function appendRestriction(string $workflow,bool $enabled):array
    {
        $record=(new ScopedCapabilityPolicyRepository())->append('personalized_pod',$workflow,'order_automation',$enabled);
        self::assertNotWPError($record);self::assertFalse($record['external_execution_authorized']);return $record;
    }

    private function job(int $id):array
    {
        global $wpdb;return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::jobs().' WHERE id=%d',$id),ARRAY_A);
    }

    private function heldFixture():array
    {
        [,$mapping,$version]=$this->product();$repo=new Repository();
        $order=PrintifyApiApprovalFixture::checked($repo->createOrder(['channel'=>'etsy','shop_reference'=>'personalized_pod','environment'=>'test','personalization_required'=>false,'currency'=>'USD']));
        $line=PrintifyApiApprovalFixture::checked($repo->addLineItem(['order_id'=>$order['id'],'product_version_id'=>$version,'provider_mapping_id'=>$mapping,'quantity'=>2]));
        foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED'] as $state)PrintifyApiApprovalFixture::checked($repo->transition('order',(int)$order['id'],$state));
        $plan=PrintifyApiApprovalFixture::checked($repo->createPlan(['order_id'=>$order['id'],'provider'=>'printify','shipping_method_metadata'=>['method'=>'standard'],'cost_snapshot_metadata'=>['base_production_cost'=>10,'shipping_estimate'=>4]]));
        foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED'] as $state)PrintifyApiApprovalFixture::checked($repo->transition('plan',(int)$plan['id'],$state));
        $intent=PrintifyApiApprovalFixture::checked($repo->createIntent(['order_id'=>$order['id'],'fulfillment_plan_id'=>$plan['id'],'intent_type'=>'PREPARE_FULFILLMENT']));
        return [(int)$order['id'],(int)$intent['id'],(int)$plan['id'],(int)$line['id']];
    }
}
