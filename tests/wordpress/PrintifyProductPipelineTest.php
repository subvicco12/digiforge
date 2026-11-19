<?php
require_once __DIR__.'/fixtures/PortalAccessFixture.php';
final class PrintifyProductPipelineTest extends WP_UnitTestCase {
 protected function setUp():void{parent::setUp();PortalAccessFixture::reset();}
 protected function tearDown():void{global $wpdb;$wpdb->query('COMMIT');PortalAccessFixture::reset();$wpdb->query('COMMIT');$wpdb->query('START TRANSACTION');parent::tearDown();}
 public function testStopAllBlocksProductTransportBeforeClaimOrCredentialAccess():void{
  PortalAccessFixture::enroll();self::assertTrue(method_exists(DigiForge\POD\PrintifyControlledTransport::class,'executeProduct'),'Product operations are not connected to the controlled transport');
  $calls=0;$t=new DigiForge\POD\PrintifyControlledTransport(static function()use(&$calls){++$calls;throw new RuntimeException('Unexpected send');});global $wpdb;$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'digiforge_pp_%'");
  $r=$t->executeProduct(str_repeat('a',64),str_repeat('b',64));self::assertWPError($r);self::assertSame('printify_product_locked',$r->get_error_code());self::assertSame(0,$calls);self::assertSame($before,(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'digiforge_pp_%'"));
 }
 public function testMissingPackageAndUnsignedAdminCannotPrepareProviderPlan():void{self::assertTrue(class_exists(DigiForge\POD\PrintifyProductPipeline::class));wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));$r=(new DigiForge\POD\PrintifyProductPipeline())->prepare(str_repeat('a',64),str_repeat('b',64),1,'PRODUCT_CREATE',[]);self::assertWPError($r);self::assertSame('printify_product_access',$r->get_error_code());}
 public function testAnOuterTransactionCannotOwnRemoteDispatchClaims():void{global $wpdb;$wpdb->query('START TRANSACTION');$r=DigiForge\POD\PrintifyProductPipeline::durableDispatchSession();self::assertWPError($r);self::assertSame('printify_product_transaction_active_or_unavailable',$r->get_error_code());}
}
