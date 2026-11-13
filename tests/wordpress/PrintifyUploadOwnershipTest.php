<?php
require_once __DIR__.'/fixtures/PortalAccessFixture.php';
require_once __DIR__.'/../../includes/POD/PrintifyUploadOwnership.php';
final class PrintifyUploadOwnershipTest extends WP_UnitTestCase
{
 protected function setUp():void{parent::setUp();PortalAccessFixture::reset();}
 protected function tearDown():void{PortalAccessFixture::reset();parent::tearDown();}
 public function testUnassignedAdministratorCannotResolveUploadSources():void{
  wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));$r=DigiForge\POD\PrintifyUploadOwnership::resolve('personalized_pod',1,[],[str_repeat('a',64)]);self::assertWPError($r);self::assertSame('printify_upload_ownership',$r->get_error_code());
 }
 public function testOtherShopCannotReusePersonalizedProductSources():void{
  PortalAccessFixture::enroll();$r=DigiForge\POD\PrintifyUploadOwnership::resolve('standard_pod',1,[],[str_repeat('a',64)]);self::assertWPError($r);self::assertSame('printify_upload_ownership',$r->get_error_code());
 }
 public function testOwnerCannotClaimImageIdWithoutCurrentProductOwnership():void{
  PortalAccessFixture::enroll();foreach([[],['remote_image_id'],[str_repeat('a',64)]] as $refs){self::assertWPError(DigiForge\POD\PrintifyUploadOwnership::resolve('personalized_pod',999999,[],$refs));}
 }
}
