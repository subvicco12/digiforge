<?php
use DigiForge\PortalAccess\{OwnerReadiness,Panel,Feature,Owner,Store};
require_once __DIR__.'/fixtures/PortalAccessFixture.php';
final class PortalOwnerReadinessTest extends WP_UnitTestCase {
 protected function setUp():void{parent::setUp();PortalAccessFixture::reset();$other=get_user_by('email',Owner::EMAIL);if($other&&$other->ID!==1)wp_update_user(['ID'=>$other->ID,'user_email'=>'legacy-readiness-fixture@example.invalid']);wp_update_user(['ID'=>1,'user_email'=>Owner::EMAIL]);}
 protected function tearDown():void{global $wpdb;$wpdb->query('COMMIT');PortalAccessFixture::reset();$wpdb->query('COMMIT');$wpdb->query('START TRANSACTION');parent::tearDown();}
 public function testInitialUiEnrollmentRequiresTheEmailAndOneTimeBootstrapPath():void{
  $id=PortalAccessFixture::owner();self::assertSame('GLOBAL_AUTHORITY_REQUIRED',OwnerReadiness::inspect('personalized_pod')['state']);
  $p=['_wpnonce'=>wp_create_nonce('digiforge_portal_owner_enroll'),'confirm'=>'CONFIRM_DIGIFORGE_ONLY','shop'=>'personalized_pod','request_id'=>wp_generate_uuid4(),'wp_role'=>'administrator','caps'=>['manage_digiforge','manage_digiforge_settings'],'approval'=>hash('sha256','synthetic1257'),'revision'=>'0'];
  self::assertWPError((new Feature())->process('digiforge_portal_owner_enroll',$p));self::assertNull(Store::read($id));
 }
 public function testMissingAuthorityAndWrongShopNeverCreateOwnerProfile():void{
  $id=PortalAccessFixture::owner();SyntheticShopAuthority::clear();self::assertSame('GLOBAL_AUTHORITY_REQUIRED',OwnerReadiness::inspect('personalized_pod')['state']);self::assertNull(Store::read($id));
  PortalAccessFixture::sign($id);self::assertSame('GLOBAL_AUTHORITY_REQUIRED',OwnerReadiness::inspect('digital')['state']);self::assertWPError(Owner::require('digital'));
 }
 public function testAdministratorEmailMismatchAndGuestRemainDenied():void{
  wp_set_current_user(0);self::assertSame('LOGIN_REQUIRED',OwnerReadiness::inspect('personalized_pod')['state']);
  wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));self::assertSame('ACCOUNT_IDENTITY_MISMATCH',OwnerReadiness::inspect('personalized_pod')['state']);
 }
 public function testExpiredOrChangedAuthorityIsNotEnrollment():void{
  $id=PortalAccessFixture::enroll();PortalAccessFixture::sign($id,time()-1);self::assertSame('SIGNED_AUTHORITY_INVALID_OR_EXPIRED',OwnerReadiness::inspect('personalized_pod')['state']);self::assertWPError(Owner::require('personalized_pod'));
 }
 public function testEnrollmentPageRequiresFreshEmailBeforeAnyOneTimeCode():void{
  PortalAccessFixture::owner();$_GET['df_shop']='personalized_pod';try{$html=Panel::bootstrap();self::assertStringContainsString('one-time enrollment code',$html);self::assertStringNotContainsString('name="caps[]"',$html);}finally{$_GET=[];}
 }
 public function testReadinessUsesCanonicalAuthoritySelection():void{
  $id=PortalAccessFixture::owner();$base=['actor_id'=>$id,'shop'=>'personalized_pod','caps'=>\DigiForge\Core\Capabilities::ALL,'expires_at'=>time()+3600,'source_approval_hash'=>hash('sha256','synthetic1257')];
  SyntheticShopAuthority::write([$base+['authority'=>'DELEGATE'],$base+['authority'=>'OWNER']]);
  self::assertWPError(Owner::signed($id,'personalized_pod'));self::assertSame('GLOBAL_AUTHORITY_REQUIRED',OwnerReadiness::inspect('personalized_pod')['state']);
 }
}
