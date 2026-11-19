<?php
require_once __DIR__.'/fixtures/PortalAccessFixture.php';
final class PrintifyLifecycleAdmissionTest extends WP_UnitTestCase
{
 protected function setUp():void{parent::setUp();PortalAccessFixture::reset();}
 protected function tearDown():void{PortalAccessFixture::reset();parent::tearDown();}
 public function testUnassignedAdministratorCannotPrepareLifecycleWrites():void{
  wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));$r=(new DigiForge\POD\PrintifyLifecycleWorkflow())->prepare('personalized_pod',1,'ORDER_CANCEL',['order_id'=>'o1']);self::assertWPError($r);self::assertSame('printify_lifecycle_access_or_operation',$r->get_error_code());
 }
 public function testMissingAuthorizationNeverReachesProvider():void{
  PortalAccessFixture::enroll();$calls=0;$t=new DigiForge\POD\PrintifyControlledTransport(static function()use(&$calls){$calls++;return [];});$r=$t->executeLifecycle(str_repeat('a',64),str_repeat('b',64));self::assertWPError($r);self::assertSame(0,$calls);
 }
 public function testPlanStorageEncryptsPrivateDestinationEvidence():void{
  PortalAccessFixture::enroll();$w=new DigiForge\POD\PrintifyLifecycleWorkflow();$method=new ReflectionMethod($w,'save');$method->setAccessible(true);$r=$method->invoke($w,'plan',['shop_key'=>'personalized_pod','operation'=>'SHIPPING_QUOTE','private_destination'=>'DO_NOT_STORE_IN_PLAINTEXT']);self::assertIsArray($r);global $wpdb;$cipher=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s','digiforge_pfy_lifecycle_plan_'.$r['hash']));self::assertStringNotContainsString('DO_NOT_STORE_IN_PLAINTEXT',$cipher);self::assertSame('DO_NOT_STORE_IN_PLAINTEXT',$w->plan($r['hash'])['private_destination']);
 }
 public function testUnanchoredReceiptCannotClaimSuccess():void{
  PortalAccessFixture::enroll();$w=new DigiForge\POD\PrintifyLifecycleWorkflow();$method=new ReflectionMethod($w,'save');$method->setAccessible(true);$r=$method->invoke($w,'receipt',['shop_key'=>'personalized_pod','operation'=>'ORDER_CANCEL','plan_hash'=>str_repeat('c',64),'status'=>'SUCCEEDED']);self::assertIsArray($r);$receipt=$w->receipt($r['hash']);self::assertWPError($receipt);self::assertSame('printify_lifecycle_receipt_audit',$receipt->get_error_code());
 }
 public function testWebhookIngressIsExactAndAuthenticatesAnonymousRequests():void{
  wp_set_current_user(0);do_action('rest_api_init');$route='/digiforge/v1/printify/webhooks/ingress/'.str_repeat('a',32);$request=new WP_REST_Request('POST',$route);$request->set_body('{}');self::assertSame(401,rest_get_server()->dispatch($request)->get_status());$wrong=new WP_REST_Request('GET',$route);self::assertSame(404,rest_get_server()->dispatch($wrong)->get_status());$wrong=new WP_REST_Request('POST',$route.'/extra');self::assertSame(404,rest_get_server()->dispatch($wrong)->get_status());
 }
 public function testWebhookCallbackIdentityCannotBeSubstituted():void{
  $request=new WP_REST_Request('POST','/digiforge/v1/printify/webhooks/ingress/'.str_repeat('a',32));$r=DigiForge\PortalAccess\RestPolicy::admit($request,['callback'=>['DigiForge\\REST\\PrintifyWebhookController','register'],'permission_callback'=>['DigiForge\\REST\\PrintifyWebhookController','permission']],null);self::assertWPError($r);self::assertSame('digiforge_portal_route_contract',$r->get_error_code());
 }
 public function testLegacyRawCatalogCannotBypassGrantsEvenWithToken():void{
  $calls=0;$client=new DigiForge\POD\PrintifyCatalogClient(static function()use(&$calls){++$calls;return [];});$r=$client->blueprints('synthetic-not-a-real-token');self::assertWPError($r);self::assertSame('catalog_governed_read_required',$r->get_error_code());self::assertSame(0,$calls);
 }
 public function testLegacyReconciliationCannotPerformUnapprovedProviderReads():void{
  $calls=0;$lookup=new DigiForge\POD\PrintifyReconciliationLookup(static function()use(&$calls){++$calls;return [];});$r=$lookup->lookup(['state'=>'EXECUTION_UNKNOWN'],1);self::assertWPError($r);self::assertSame('printify_governed_reconciliation_required',$r->get_error_code());self::assertSame(0,$calls);
 }
}
