<?php
declare(strict_types=1);
final class ProductFactorySchedulingAcceptanceTest extends WP_UnitTestCase {
 protected function setUp():void {parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();}
 public function testProtectedInitialDispatcherCannotWriteCheckpointOrScheduleStage():void {
  $key='protected-dispatch-'.wp_generate_uuid4();$active='digiforge_u3_active_1_digital';delete_option($active);$cronBefore=_get_cron_array();$calls=0;
  $http=static function()use(&$calls){$calls++;return new WP_Error('offline_fixture','Provider forbidden.');};add_filter('pre_http_request',$http);
  try{(new DigiForge\ProductFactory\ApprovalAutomation())->run(1,'digital',$key);}finally{remove_filter('pre_http_request',$http);}
  self::assertFalse(get_option($active,false));self::assertSame($cronBefore,_get_cron_array());self::assertSame(0,$calls);self::assertTrue(DigiForge\Core\Settings::safety_locked());
 }
 public function testExistingStageRetryAndManifestRepairCeilingsDenyWithoutMutationOrProvider():void {
  $worker=new DigiForge\ProductFactory\ApprovalAutomation();$key='fixture-bounded-'.wp_generate_uuid4();$state=['development_start_retries'=>3,'manifest_repair_attempts'=>2];update_option($key,$state,false);$calls=0;
  $http=static function()use(&$calls){$calls++;return new WP_Error('offline_fixture','Provider forbidden.');};add_filter('pre_http_request',$http);
  try{
   $retry=new ReflectionMethod($worker,'retryStage');self::assertFalse($retry->invoke($worker,1,'digital','fixture','develop',new WP_Error('digiforge_launch_ai_rate_limited','Fixture'),$key,$state,'development_start_retries'));
   $repair=new ReflectionMethod($worker,'restartManifest');self::assertFalse($repair->invoke($worker,1,'digital','fixture',[],new WP_Error('digiforge_production_fixture','Fixture'),$key,$state,new DigiForge\ProductFactory\Orchestrator()));
  }finally{remove_filter('pre_http_request',$http);}
  self::assertSame($state,get_option($key));self::assertSame(0,$calls);self::assertTrue(DigiForge\Core\Settings::safety_locked());
 }
}
