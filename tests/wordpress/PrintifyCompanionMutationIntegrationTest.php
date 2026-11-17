<?php
declare(strict_types=1);
use DigiForgePrintifyConnector\Preparation\{MutationCoreInterface,VersionedMutationHost,PreparationService,MutationFence};
final class PrintifyCompanionMutationIntegrationTest extends WP_UnitTestCase {
 public function set_up():void{parent::set_up();require_once dirname(__DIR__,2).'/companion/digiforge-printify-connector/digiforge-printify-connector.php';}
 public function testPluginExposesOnlyDefaultDeniedMutationAdapter():void {
  self::assertTrue(method_exists(DigiForgePrintifyConnector\Plugin::class,'mutationHost'));
  $routes=rest_get_server()->get_routes();$cron=_get_cron_array();global $wpdb;$count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}");$calls=0;$filter=static function($pre)use(&$calls){$calls++;return new WP_Error('synthetic_no_network');};add_filter('pre_http_request',$filter,1);
  try{$h=DigiForgePrintifyConnector\Plugin::mutationHost();self::assertInstanceOf(VersionedMutationHost::class,$h);$i=$this->intent(1);self::assertWPError($h->register($i));self::assertWPError($h->recover($i));self::assertSame(0,$calls);self::assertSame($routes,rest_get_server()->get_routes());self::assertSame($cron,_get_cron_array());self::assertSame($count,(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}"));self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));}finally{remove_filter('pre_http_request',$filter,1);}
 }
 private function intent(int $actor):DigiForgePrintifyConnector\Preparation\MutationIntent {
  [$b,$e,$s]=(require __DIR__.'/../fixtures/printify-connector/stage34/artwork-fixture.php')();return (new PreparationService())->upload($s,$b,$e,['actor_id'=>$actor,'logical_reference'=>'core-asset-1','approval_reference'=>str_repeat('d',64),'authorization_revision'=>1,'product_version'=>1,'expected_product_version'=>0]);
 }
 public function testCorePortRemainsResponsibleForAuthenticatedActorAndRevocation():void {
  require_once __DIR__.'/../fixtures/printify-connector/batch2/SyntheticMutationCore.php';$actor=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($actor);$i=$this->intent($actor);
  $port=new class($actor) implements MutationCoreInterface {
   public SyntheticMutationCore $core;public function __construct(private int $actor){$this->core=new SyntheticMutationCore();}
   public function version():string{return '1.0.0';}
   public function exchange(array $q):array|WP_Error {if(get_current_user_id()!==$this->actor||!current_user_can('manage_options')||$q['binding']['actor_id']!==$this->actor)return new WP_Error('synthetic_actor_denied');return $this->core->exchange($q);}
  };
  $h=new VersionedMutationHost($port);$f=new MutationFence($h);self::assertIsArray($f->register($i));self::assertIsArray($f->reserve($i));wp_set_current_user(0);self::assertWPError($h->acceptReceipt($i,$port->core->receipt($i)));wp_set_current_user($actor);$port->core->fault='revoked';self::assertWPError($h->recover($i));$port->core->fault='';self::assertSame('FENCED',$h->recover($i)['state']);self::assertSame('METADATA_RECONCILED',$h->acceptReceipt($i,$port->core->receipt($i))['state']);
 }
}
