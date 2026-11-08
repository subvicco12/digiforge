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
}
