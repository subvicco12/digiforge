<?php
declare(strict_types=1);
use DigiForgePrintifyConnector\Preparation\{WorkflowCoreInterface,WorkflowCoordinator,WorkflowPanel,OrderReadModel,PreparationService,VersionedMutationHost,MutationFence};
use DigiForge\Connector\Printify\ShopBinding;
final class PrintifyCompanionBatch4IntegrationTest extends WP_UnitTestCase {
 public function set_up():void{parent::set_up();require_once dirname(__DIR__,2).'/companion/digiforge-printify-connector/digiforge-printify-connector.php';}
 public function testNoNativePublicationOrWorkflowAuthorityFromWpAdmin():void{[$s,$input,$e,$ctx,$order,$lineage]=(require __DIR__.'/../fixtures/printify-connector/batch4/fixture.php')();$actor=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($actor);$ctx['actor_id']=$actor;$routes=rest_get_server()->get_routes();$cron=_get_cron_array();$calls=0;$filter=static function($pre)use(&$calls){$calls++;return new WP_Error('no_http');};add_filter('pre_http_request',$filter,1);
  try{$i=(new PreparationService())->publish($s,$input,$e,$ctx);self::assertWPError((new MutationFence(DigiForgePrintifyConnector\Plugin::mutationHost()))->register($i));self::assertWPError(DigiForgePrintifyConnector\Plugin::workflows()->ingestOrder($s,$actor,OrderReadModel::fromProvider($s,$order,$lineage,str_repeat('a',64))));self::assertSame(0,$calls);self::assertSame($routes,rest_get_server()->get_routes());self::assertSame($cron,_get_cron_array());self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));}finally{remove_filter('pre_http_request',$filter,1);}
 }
 public function testSyntheticWorkflowCoreRequiresCurrentActorAndKeepsExportsPrivate():void {
  require_once __DIR__.'/../fixtures/printify-connector/batch4/SyntheticWorkflowCore.php';[$s,,,,$order,$lineage,$event,$secret]=(require __DIR__.'/../fixtures/printify-connector/batch4/fixture.php')();$actor=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($actor);
  $port=new class($actor) implements WorkflowCoreInterface {
   private SyntheticWorkflowCore $core;public function __construct(private int $actor){$this->core=new SyntheticWorkflowCore();}public function version():string{return '1.0.0';}public function workflowVersion():string{return '1.0.0';}public function exchange(array $q):array|WP_Error{return new WP_Error('no_dispatch');}
   public function authorizeWorkflow(ShopBinding $s,int $actor,string $action):array|WP_Error{if(get_current_user_id()!==$this->actor||$actor!==$this->actor||!current_user_can('manage_options'))return new WP_Error('synthetic_actor_denied');return $this->core->authorizeWorkflow($s,$actor,$action);}
   public function withWebhookSecret(ShopBinding $s,callable $v):mixed{return $this->core->withWebhookSecret($s,$v);}public function recordWorkflow(array $q):array|WP_Error{return $this->core->recordWorkflow($q);}public function inspectWorkflows(ShopBinding $s,int $a,int $limit):array|WP_Error{return $this->core->inspectWorkflows($s,$a,$limit);}
  };
  $w=new WorkflowCoordinator($port);self::assertSame('RECORDED',$w->ingestOrder($s,$actor,OrderReadModel::fromProvider($s,$order,$lineage,str_repeat('a',64)))['disposition']);$raw=json_encode($event);self::assertSame('RECORDED',$w->webhook($s,$actor,$raw,'sha256='.hash_hmac('sha256',$raw,$secret))['disposition']);self::assertStringContainsString('Manual review',WorkflowPanel::render($w,$s,$actor));self::assertStringNotContainsString('Private Street',$w->export($s,$actor));wp_set_current_user(0);self::assertWPError($w->snapshot($s,$actor));
 }
}
