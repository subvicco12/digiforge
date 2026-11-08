<?php
use DigiForge\Portal\ShopAccessPanel;
use DigiForge\PortalAccess\{Store,Session,Owner,GlobalOwnerAuthority};
use DigiForge\Security\{ShopAccessRepository,ShopAccessPolicy};
require_once __DIR__.'/fixtures/PortalAccessFixture.php';
final class LegacyOperatorsCompatibilityTest extends WP_UnitTestCase {
 protected function setUp():void{parent::setUp();PortalAccessFixture::reset();$_GET=['df_shop'=>'all'];}
 protected function tearDown():void{global $wpdb;$_GET=[];$wpdb->query('COMMIT');PortalAccessFixture::reset();$wpdb->query('COMMIT');$wpdb->query('START TRANSACTION');parent::tearDown();}
 public function testDatabaseOwnerSeesAllFourScopesWithoutSignedRootOrLegacyEnrollmentForms():void {
  PortalAccessFixture::enroll();SyntheticShopAuthority::clear();$html=(new ShopAccessPanel())->render();self::assertStringNotContainsString('Independent signed owner/delegate authority is missing',$html);self::assertStringContainsString('Global Primary Owner',$html);
  foreach(ShopAccessPolicy::canonicalShops() as $shop)self::assertStringContainsString('df_shop='.$shop,$html);self::assertStringNotContainsString('Enroll my signed owner scope',$html);self::assertStringNotContainsString('name="operation" value="ENROLL"',$html);self::assertSame(2,Store::read(1)['revision']);
 }
 public function testOwnerCannotUseLegacyMutationOrCreateCompetingOwner():void {
  PortalAccessFixture::enroll();$p=['_wpnonce'=>wp_create_nonce(ShopAccessPanel::ACTION),'confirm'=>'CONFIRM_EXPLICIT_SHOP_ACCESS','shop'=>'digital','request_id'=>wp_generate_uuid4(),'expected_revision'=>'1','operation'=>'ENROLL','role'=>'administrator','caps'=>DigiForge\Core\Capabilities::ALL,'expires_at'=>(string)(time()+600),'approval'=>str_repeat('a',64)];
  self::assertWPError((new ShopAccessPanel())->processBrowser($p));$p['operation']='GRANT';$p['subject_id']=(string)self::factory()->user->create(['role'=>'administrator']);self::assertWPError((new ShopAccessPanel())->processBrowser($p));self::assertSame(2,Store::read(1)['revision']);
 }
 public function testRevokedOrMissingSessionOwnerNeverFallsBackToSignedRecords():void {
  $p=PortalAccessFixture::enroll();unset($_COOKIE[Session::name()]);$h=(new ShopAccessPanel())->render();self::assertStringNotContainsString('df_view=users',$h);self::assertStringNotContainsString('name="operation"',$h);self::assertStringContainsString('unavailable or expired',$h);
 }
 public function testRevokedNativeGrantDeniesWholeGlobalOperatorsView():void {
  PortalAccessFixture::enroll();$p=Store::read(1);$repo=new ShopAccessRepository();$s=$repo->read('digital');self::assertNotWPError($repo->revoke('digital',$p['global_grants']['digital'],wp_generate_uuid4(),$s['revision']));$h=(new ShopAccessPanel())->render();self::assertStringNotContainsString('df_view=users',$h);self::assertStringContainsString('unavailable or expired',$h);
 }
 public function testUnassignedAdministratorAndDelegatedViewerCannotEnterOwnerOperatorsView():void {
  PortalAccessFixture::enroll();$id=self::factory()->user->create(['role'=>'administrator']);Store::change($id,'Viewer',['personalized_pod'=>['research']],true,0,wp_generate_uuid4());PortalAccessFixture::login($id);Session::start();$h=(new ShopAccessPanel())->render();self::assertStringNotContainsString('df_view=users',$h);self::assertStringNotContainsString('name="operation"',$h);self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital','manage_digiforge_settings'));
  PortalAccessFixture::login(self::factory()->user->create(['role'=>'administrator']));$h=(new ShopAccessPanel())->render();self::assertStringNotContainsString('df_view=users',$h);self::assertStringNotContainsString('name="operation"',$h);
 }
 public function testIndependentSignedDelegateKeepsExactShopAndSignatureBoundary():void {
  PortalAccessFixture::enroll();$actor=self::factory()->user->create(['role'=>'administrator']);$caps=['manage_digiforge','manage_digiforge_settings'];$repo=new ShopAccessRepository();$state=$repo->read('personalized_pod');self::assertNotWPError($repo->grant($actor,'personalized_pod','administrator',$caps,time()+600,wp_generate_uuid4(),$state['revision']));
  SyntheticShopAuthority::write([['actor_id'=>$actor,'shop'=>'personalized_pod','caps'=>$caps,'expires_at'=>time()+1800,'source_approval_hash'=>hash('sha256','independent-delegate'),'authority'=>'DELEGATE']]);PortalAccessFixture::login($actor);$_GET=['df_shop'=>'personalized_pod'];$h=(new ShopAccessPanel())->render();self::assertStringContainsString('Assign an existing operator',$h);self::assertStringNotContainsString('Enroll my signed owner scope',$h);
  $_GET=['df_shop'=>'digital'];$h=(new ShopAccessPanel())->render();self::assertStringNotContainsString('Assign an existing operator',$h);self::assertStringNotContainsString('name="operation"',$h);
  SyntheticShopAuthority::clear();$_GET=['df_shop'=>'personalized_pod'];self::assertStringNotContainsString('name="operation"',(new ShopAccessPanel())->render());
 }
 public function testRevokedProfileDeniesView():void {
  PortalAccessFixture::enroll();self::assertNotWPError(DigiForge\PortalAccess\OwnerBootstrap::revoke(wp_generate_uuid4()));self::assertStringContainsString('unavailable or expired',(new ShopAccessPanel())->render());
 }
 public function testOwnerSelectedShopAndSchedulerDetailsStayReadOnly():void {
  PortalAccessFixture::enroll();$_GET=['df_shop'=>'digital'];$h=(new ShopAccessPanel())->render();self::assertStringContainsString('df_shop=digital',$h);self::assertStringNotContainsString('df_shop=jewelry',$h);$_GET=['df_view'=>'automation','df_shop'=>'all'];$h=(new DigiForge\PortalAccess\Feature())->after('Existing task evidence','digiforge_admin_portal',[],[]);self::assertStringContainsString('Action Scheduler dependency',$h);self::assertStringContainsString('Optional dependency',$h);self::assertSame(2,Store::read(1)['revision']);
 }

 public function testExpiredUnrevokedNativeAssignmentShowsRenewalGuidanceNotSignedRootWarning():void {
  PortalAccessFixture::enroll();$p=Store::read(1);global $wpdb;$repo=new ShopAccessRepository();$state=$repo->read('digital');$id=$p['global_grants']['digital'];$state['grants'][$id]['expires_at']=time()-1;$key='digiforge_shop_access_event_'.$id;$event=json_decode($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key)),true);$event['grant']=$state['grants'][$id];$event['created_at']=time()-3601;$raw=wp_json_encode($event);$wpdb->update($wpdb->options,['option_value'=>$raw],['option_name'=>$key]);$wpdb->update($wpdb->options,['option_value'=>wp_json_encode($state)],['option_name'=>'digiforge_shop_access_state_digital']);$wpdb->update(DigiForge\Database\Tables::audit_log(),['context'=>$raw],['object_type'=>'shop_access','event_type'=>'shop_access_grant','object_id'=>$id],['%s'],['%s','%s','%s']);
  self::assertTrue(GlobalOwnerAuthority::active($p,true));$h=(new ShopAccessPanel())->render();self::assertStringContainsString('unavailable or expired',$h);self::assertStringNotContainsString('authority envelope',$h);self::assertStringNotContainsString('df_view=users',$h);self::assertSame(2,Store::read(1)['revision']);
 }

}
