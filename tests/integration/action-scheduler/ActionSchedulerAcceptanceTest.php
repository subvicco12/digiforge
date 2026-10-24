<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/wordpress/fixtures/ShopAccessFixture.php';

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\ProductFactory\ApprovalAutomation;
use DigiForge\Queue\ScheduledTaskReadModel;
use DigiForge\Security\Logger;

/** p151/table22r13: official 3.9.3 SQL store/runner; protected local callbacks, no live execution. */
final class ActionSchedulerAcceptanceTest extends WP_UnitTestCase
{
    private int $httpCalls=0;
    private Closure $http;
    private bool $addedLogger=false;
    protected function setUp(): void
    {
        parent::setUp();self::assertSame('3.9.3',ActionScheduler_Versions::instance()->latest_version());self::assertTrue(ActionScheduler::is_initialized());
        $runtime=realpath((string)getenv('DIGIFORGE_TEST_ACTION_SCHEDULER_DIR'));self::assertStringStartsWith($runtime.DIRECTORY_SEPARATOR,(new ReflectionFunction('as_enqueue_async_action'))->getFileName());
        DigiForge\Core\Activator::activate();Settings::protectProduction();ShopAccessFixture::reset();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));ShopAccessFixture::authorizeActor(get_current_user_id(),['digital','personalized_pod','standard_pod','jewelry'],DigiForge\Core\Capabilities::ALL);
        if(has_action('digiforge_log',[Logger::class,'write'])===false){add_action('digiforge_log',[Logger::class,'write'],10,4);$this->addedLogger=true;}
        $this->http=function(){++$this->httpCalls;return new WP_Error('offline_as_acceptance','No HTTP permitted.');};add_filter('pre_http_request',$this->http);
    }
    protected function tearDown(): void
    {
        remove_filter('pre_http_request',$this->http);if($this->addedLogger)remove_action('digiforge_log',[Logger::class,'write'],10);self::assertSame(0,$this->httpCalls);self::assertTrue(Settings::get('stop_all',true));self::assertFalse(Settings::get('automation_armed',false));self::assertTrue(Settings::safety_locked());parent::tearDown();
    }
    private function approvedCandidate(): array
    {
        $repo=new DigiForge\Research\Repository();$candidate=$repo->createCandidate(['shop_key'=>'digital','title'=>'Official AS fixture '.wp_generate_uuid4()]);self::assertNotWPError($candidate);self::assertNotWPError($repo->review((int)$candidate['id'],'APPROVED','Synthetic local scheduling fixture.'));
        $args=[(int)$candidate['id'],'digital','u3-auto-candidate-'.$candidate['id'].'-digital'];return [$candidate,$args];
    }
    private function actionId(string $hook,array $args): int
    {
        $ids=as_get_scheduled_actions(['hook'=>$hook,'args'=>$args,'group'=>'digiforge','status'=>ActionScheduler_Store::STATUS_PENDING,'per_page'=>10],'ids');self::assertCount(1,$ids);return (int)$ids[0];
    }
    public function testActualApprovedCandidateUsesOfficialActionSchedulerBeforeWpCronFallback(): void
    {
        [$candidate,$args]=$this->approvedCandidate();$id=$this->actionId('digiforge_u3_build_product',$args);$action=ActionScheduler::store()->fetch_action($id);
        self::assertSame('digiforge_u3_build_product',$action->get_hook());self::assertSame($args,$action->get_args());self::assertSame('digiforge',$action->get_group());self::assertSame(ActionScheduler_Store::STATUS_PENDING,ActionScheduler::store()->get_status($id));self::assertFalse(wp_next_scheduled('digiforge_u3_build_product',$args));
        global $wpdb;$stored=$wpdb->get_row($wpdb->prepare('SELECT hook,status,args FROM '.$wpdb->prefix.'actionscheduler_actions WHERE action_id=%d',$id),ARRAY_A);self::assertIsArray($stored);self::assertSame('pending',$stored['status']);self::assertSame($args,json_decode($stored['args'],true));
        $audit=$wpdb->get_var($wpdb->prepare("SELECT context FROM ".Tables::audit_log()." WHERE event_type='u3_product_build_scheduled' AND object_id=%s ORDER BY id DESC LIMIT 1",(string)$candidate['id']));$context=json_decode((string)$audit,true);self::assertSame('action_scheduler_async',$context['scheduler']);self::assertSame($id,$context['action_id']);
    }
    public function testImmediateAndDelayedStagesPersistInOfficialStoreWithoutCronFallback(): void
    {
        $workflow=new ApprovalAutomation();$method=new ReflectionMethod($workflow,'scheduleStage');$run='synthetic-stage-'.wp_generate_uuid4();
        foreach(['develop'=>0,'manifest_poll'=>600]as $stage=>$delay){self::assertTrue($method->invoke($workflow,8801,'digital',$run,$stage,$delay));$args=[8801,'digital',sanitize_key($run),$stage];$id=$this->actionId('digiforge_u3_build_product_stage',$args);$action=ActionScheduler::store()->fetch_action($id);self::assertSame($args,$action->get_args());self::assertFalse(wp_next_scheduled('digiforge_u3_build_product_stage',$args));if($delay>0)self::assertGreaterThanOrEqual(time()+590,$action->get_schedule()->get_date()->getTimestamp());}
    }
    public function testOfficialRunnerExecutesProtectedDispatcherWithoutProviderOrCheckpointWrites(): void
    {
        [$candidate,$args]=$this->approvedCandidate();$id=$this->actionId('digiforge_u3_build_product',$args);$key='digiforge_u3_'.substr(hash('sha256',$args[2]),0,32);$before=get_option($key,false);global $wpdb;$versions=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::product_versions());
        self::assertGreaterThanOrEqual(1,ActionScheduler_QueueRunner::instance()->run('DigiForge isolated acceptance'));
        self::assertSame(ActionScheduler_Store::STATUS_COMPLETE,ActionScheduler::store()->get_status($id));self::assertSame($before,get_option($key,false));self::assertSame($versions,(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::product_versions()));
        $context=json_decode((string)$wpdb->get_var($wpdb->prepare("SELECT context FROM ".Tables::audit_log()." WHERE event_type='u3_product_build_blocked' AND object_id=%s ORDER BY id DESC LIMIT 1",(string)$candidate['id'])),true);self::assertSame('dispatcher_not_effective',$context['reason']);self::assertFalse($context['external_actions']);self::assertNotEmpty(ActionScheduler::logger()->get_logs($id));
    }
    public function testActualOfficialPendingTaskAppearsInReadonlyPortalWithoutExecutingOrExposingRunKey(): void
    {
        [$candidate,$args]=$this->approvedCandidate();$id=$this->actionId('digiforge_u3_build_product',$args);$before=ActionScheduler::store()->get_status($id);$logs=count(ActionScheduler::logger()->get_logs($id));$snapshot=(new ScheduledTaskReadModel())->snapshot();self::assertSame('AVAILABLE',$snapshot['query_state']['action_scheduler']);$matches=array_values(array_filter($snapshot['items'],static fn(array $x):bool=>$x['scheduler']==='ACTION_SCHEDULER'&&$x['action_id']===$id));self::assertCount(1,$matches);self::assertSame((int)$candidate['id'],$matches[0]['candidate_id']);self::assertSame(hash('sha256',$args[2]),$matches[0]['run_key_sha256']);
        $prior=$_GET;try{$_GET=['df_view'=>'automation','df_shop'=>'digital'];$html=(new DigiForge\Portal\Portal())->render();}finally{$_GET=$prior;}
        self::assertStringContainsString('Action Scheduler evidence</span><b>AVAILABLE',$html);self::assertStringContainsString('<td>ACTION_SCHEDULER</td><td>#'.$candidate['id'].'</td><td>digital</td><td>dispatcher</td><td>PENDING</td><td>NO</td>',$html);self::assertStringNotContainsString($args[2],$html);self::assertSame($before,ActionScheduler::store()->get_status($id));self::assertSame($logs,count(ActionScheduler::logger()->get_logs($id)));self::assertFalse($snapshot['external_execution_authorized']);
    }
}
