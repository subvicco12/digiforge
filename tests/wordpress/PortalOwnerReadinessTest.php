<?php
use DigiForge\PortalAccess\{OwnerReadiness,Panel,Feature,Owner,Store};
require_once __DIR__.'/fixtures/PortalAccessFixture.php';
final class PortalOwnerReadinessTest extends WP_UnitTestCase {
 protected function setUp():void{parent::setUp();PortalAccessFixture::reset();}
 protected function tearDown():void{global $wpdb;$wpdb->query('COMMIT');PortalAccessFixture::reset();$wpdb->query('COMMIT');$wpdb->query('START TRANSACTION');parent::tearDown();}
 public function testSignedOwnerCanCompleteEnrollmentThroughAuthenticatedUiHandler():void{
  PortalAccessFixture::owner();self::assertSame('READY_TO_ENROLL',OwnerReadiness::inspect('personalized_pod')['state']);
  $p=['_wpnonce'=>wp_create_nonce('digiforge_portal_owner_enroll'),'confirm'=>'CONFIRM_DIGIFORGE_ONLY','shop'=>'personalized_pod','request_id'=>wp_generate_uuid4(),'wp_role'=>'administrator','caps'=>['manage_digiforge','manage_digiforge_settings','manage_digiforge_research','manage_digiforge_pod'],'approval'=>hash('sha256','synthetic1257'),'revision'=>'0'];
  self::assertIsArray((new Feature())->process('digiforge_portal_owner_enroll',$p));self::assertSame('ENROLLED',OwnerReadiness::inspect('personalized_pod')['state']);self::assertTrue(Owner::require('personalized_pod'));self::assertIsArray(Store::read(get_current_user_id()));
 }
 public function testMissingAuthorityAndWrongShopNeverCreateOwnerProfile():void{
  $id=PortalAccessFixture::owner();SyntheticShopAuthority::clear();self::assertSame('OWNER_SHOP_SCOPE_MISSING',OwnerReadiness::inspect('personalized_pod')['state']);self::assertNull(Store::read($id));
  PortalAccessFixture::sign($id);self::assertSame('OWNER_SHOP_SCOPE_MISSING',OwnerReadiness::inspect('digital')['state']);self::assertWPError(Owner::require('digital'));
 }
 public function testAdministratorEmailMismatchAndGuestRemainDenied():void{
  wp_set_current_user(0);self::assertSame('LOGIN_REQUIRED',OwnerReadiness::inspect('personalized_pod')['state']);
  wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));self::assertSame('ACCOUNT_IDENTITY_MISMATCH',OwnerReadiness::inspect('personalized_pod')['state']);
 }
 public function testExpiredOrChangedAuthorityIsNotEnrollment():void{
  $id=PortalAccessFixture::enroll();PortalAccessFixture::sign($id,time()-1);self::assertSame('SIGNED_AUTHORITY_INVALID_OR_EXPIRED',OwnerReadiness::inspect('personalized_pod')['state']);self::assertWPError(Owner::require('personalized_pod'));
 }
 public function testEnrollmentPageShowsExactBlockerAndRequiresMinimumCapabilities():void{
  PortalAccessFixture::owner();$_GET['df_shop']='personalized_pod';try{$html=Panel::bootstrap();self::assertStringContainsString('READY_TO_ENROLL',$html);self::assertStringContainsString('value="manage_digiforge" checked required',$html);self::assertStringContainsString('value="manage_digiforge_settings" checked required',$html);SyntheticShopAuthority::clear();self::assertStringContainsString('OWNER_SHOP_SCOPE_MISSING',Panel::bootstrap());}finally{$_GET=[];}
 }
 public function testReadinessUsesCanonicalAuthoritySelection():void{
  $id=PortalAccessFixture::owner();$base=['actor_id'=>$id,'shop'=>'personalized_pod','caps'=>\DigiForge\Core\Capabilities::ALL,'expires_at'=>time()+3600,'source_approval_hash'=>hash('sha256','synthetic1257')];
  SyntheticShopAuthority::write([$base+['authority'=>'DELEGATE'],$base+['authority'=>'OWNER']]);
  self::assertWPError(Owner::signed($id,'personalized_pod'));self::assertSame('OWNER_SHOP_SCOPE_MISSING',OwnerReadiness::inspect('personalized_pod')['state']);
 }
}
