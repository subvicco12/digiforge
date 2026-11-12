<?php
require_once __DIR__.'/fixtures/PortalAccessFixture.php';
final class PrintifyApiReadBoundaryTest extends WP_UnitTestCase
{
 protected function setUp():void{parent::setUp();PortalAccessFixture::reset();}
 protected function tearDown():void{PortalAccessFixture::reset();parent::tearDown();}
 public function testUnsignedAdministratorCannotPrepareReadPlan():void{
  wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
  $r=(new DigiForge\POD\PrintifyApiReadWorkflow())->prepare('personalized_pod',1,'SHOPS_READ',[]);
  self::assertWPError($r);self::assertSame('printify_api_read_access_or_operation',$r->get_error_code());
 }
 public function testEnrolledOwnerStillNeedsExactReviewedProviderBinding():void{
  PortalAccessFixture::enroll();
  $r=(new DigiForge\POD\PrintifyApiReadWorkflow())->prepare('personalized_pod',999999,'SHOPS_READ',[]);
  self::assertWPError($r);
 }
 public function testMutationCannotEnterReadServiceEvenForOwner():void{
  PortalAccessFixture::enroll();
  $r=(new DigiForge\POD\PrintifyApiReadWorkflow())->prepare('personalized_pod',1,'PRODUCT_PUBLISH',['product_id'=>'p1','title'=>true]);
  self::assertWPError($r);self::assertSame('printify_api_read_access_or_operation',$r->get_error_code());
 }
 public function testMissingAuthorizationAndOuterTransactionNeverSend():void{
  $calls=0;$transport=new DigiForge\POD\PrintifyControlledTransport(static function()use(&$calls){++$calls;throw new RuntimeException('No egress');});
  $r=$transport->executeApiRead(str_repeat('a',64),str_repeat('b',64));
  self::assertWPError($r);self::assertSame(0,$calls);
 }
 public function testUnapprovedShopCannotPrepareDiscovery():void{
  PortalAccessFixture::enroll();
  $r=(new DigiForge\POD\PrintifyApiReadWorkflow())->prepare('digital',1,'SHOPS_READ',[]);
  self::assertWPError($r);self::assertSame('printify_api_read_access_or_operation',$r->get_error_code());
 }
 public function testReceiptWithoutDurableAuditAnchorCannotClaimSuccess():void{
  PortalAccessFixture::enroll();$workflow=new DigiForge\POD\PrintifyApiReadWorkflow();
  $method=new ReflectionMethod($workflow,'save');$method->setAccessible(true);
  $saved=$method->invoke($workflow,'receipt',['shop_key'=>'personalized_pod','plan_hash'=>str_repeat('c',64),'status'=>'SUCCEEDED']);
  self::assertIsArray($saved);$r=$workflow->receipt($saved['hash']);self::assertWPError($r);self::assertSame('printify_api_read_receipt_audit',$r->get_error_code());
 }
 public function testLegacyCatalogCannotBypassGovernedGrantPipeline():void{
  $calls=0;$service=new DigiForge\POD\PrintifyCatalogSyncService(static function()use(&$calls){++$calls;throw new RuntimeException('No legacy transport');});
  DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();
  foreach([$service->syncBlueprint(1,1),$service->syncProviderVariants(1,1,1)] as $r){self::assertWPError($r);self::assertSame('digiforge_printify_locked',$r->get_error_code());self::assertFalse($r->get_error_data()['network_request_attempted']);}
  self::assertSame(0,$calls);
  // Synthetic query observations cannot replace concrete signed admission.
  $values=['activation_authorized'=>true,'automation_armed'=>true,'stop_all'=>false,'printify'=>true,'printify_activation_authorized'=>true];$table=DigiForge\Database\Tables::settings();
  $observe=static function(string $sql)use($values,$table):string{foreach($values as $key=>$value)if(str_contains($sql,'SELECT setting_value, setting_type FROM '.$table)&&str_contains($sql,"setting_key = '".$key."'"))return "SELECT '".($value?'true':'false')."' AS setting_value, '".DigiForge\Core\Config::setting_type($key)."' AS setting_type";return $sql;};
  add_filter('query',$observe);
  try{self::assertTrue(DigiForge\Core\Settings::is_enabled('printify'));foreach([$service->syncBlueprint(1,1),$service->syncProviderVariants(1,1,1)] as $r){self::assertWPError($r);self::assertSame('digiforge_printify_governed_catalog_required',$r->get_error_code());self::assertFalse($r->get_error_data()['network_request_attempted']);}self::assertSame(0,$calls);}finally{remove_filter('query',$observe);}
  self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));
 }
 public function testConnectionPermissionDoesNotGrantOrderModuleAccess():void{
  PortalAccessFixture::enroll();$user=wp_get_current_user();$user->add_cap('manage_digiforge_orders',false);
  try{$r=(new DigiForge\POD\PrintifyApiReadWorkflow())->prepare('personalized_pod',1,'ORDER_READ',['order_id'=>'o1']);self::assertWPError($r);self::assertSame('printify_api_read_access_or_operation',$r->get_error_code());}
  finally{$user->add_cap('manage_digiforge_orders',true);}
 }
}
