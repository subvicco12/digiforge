<?php
use DigiForge\PortalAccess\{Panel,Policy,Store,Session};
require_once __DIR__.'/fixtures/PortalAccessFixture.php';
final class PortalEmptyModulesTest extends WP_UnitTestCase {
 protected function setUp():void{parent::setUp();PortalAccessFixture::reset();}
 protected function tearDown():void{global $wpdb;$wpdb->query('COMMIT');PortalAccessFixture::reset();$wpdb->query('COMMIT');$wpdb->query('START TRANSACTION');parent::tearDown();}
 private function payload(int $id,int $revision):array{return ['confirm'=>'CONFIRM_DIGIFORGE_ONLY','shop'=>'personalized_pod','request_id'=>wp_generate_uuid4(),'operation'=>'CHANGE','role'=>'Operator','subject_id'=>(string)$id,'revision'=>(string)$revision,'enabled'=>'1'];}
 public function testOnlyExplicitExistingProfileRemovalAllowsEmptyPermissions():void{
  self::assertWPError(Policy::permissions('Operator',[]));
  self::assertSame([],Policy::permissions('Operator',[],true));
  self::assertWPError(Policy::permissions('Primary Owner',[],true));
 }
 public function testMissingModulesRequiresExactRenderedMarkerAndChangeOperation():void{
  PortalAccessFixture::enroll();$id=self::factory()->user->create(['role'=>'administrator']);
  $s=Store::change($id,'Operator',['personalized_pod'=>['research']],true,0,wp_generate_uuid4());self::assertNotWPError($s);
  $p=$this->payload($id,$s['revision']);
  self::assertWPError(Panel::process('digiforge_portal_users',$p));
  foreach(['0',1,[],null] as $marker){$p['modules_present']=$marker;self::assertWPError(Panel::process('digiforge_portal_users',$p));}
  $p['modules_present']='1';$p['modules']='research';self::assertWPError(Panel::process('digiforge_portal_users',$p));unset($p['modules']);
  $p['operation']='INVITE';$p['email']=get_userdata($id)->user_email;$p['lifetime']='600';self::assertWPError(Panel::process('digiforge_portal_users',$p));
  self::assertSame($s,Store::read($id));
  self::assertStringContainsString('name="modules_present" value="1"',Panel::render());
 }
 public function testClearingLastModulePersistsDenyAllWithoutNativeGrantAndInvalidatesSession():void{
  $owner=PortalAccessFixture::enroll();$id=self::factory()->user->create(['role'=>'administrator']);
  $s=Store::change($id,'Operator',['personalized_pod'=>['research']],true,0,wp_generate_uuid4());self::assertNotWPError($s);
  PortalAccessFixture::login($id);self::assertNotWPError(Session::start());$cookie=$_COOKIE[Session::name()];$wp=$_COOKIE[LOGGED_IN_COOKIE];$token=wp_get_session_token();$account=get_userdata($id)->to_array();
  PortalAccessFixture::login($owner);self::assertNotWPError(Session::start());
  $repo=new \DigiForge\Security\ShopAccessRepository();$native=$repo->read('personalized_pod');
  $p=$this->payload($id,$s['revision']);$p['modules_present']='1';$r=Panel::process('digiforge_portal_users',$p);
  self::assertNotWPError($r);self::assertFalse($r['enabled']);self::assertSame([],$r['shops']);self::assertContains('personalized_pod',$r['managed_shops']);self::assertSame($s['revision']+1,$r['revision']);self::assertSame($s['generation']+1,$r['generation']);
  self::assertSame($r,Store::read($id));self::assertSame($native,$repo->read('personalized_pod'));self::assertSame($account,get_userdata($id)->to_array());
  global $wpdb;$event=json_decode($wpdb->get_var($wpdb->prepare('SELECT context FROM '.\DigiForge\Database\Tables::audit_log()." WHERE object_type='portal_access_profile' AND object_id=%s AND event_type='portal_profile_changed' ORDER BY id DESC LIMIT 1",(string)$id)),true);self::assertSame($r,$event['state']);
  wp_set_current_user($id);$_COOKIE[LOGGED_IN_COOKIE]=$wp;$_COOKIE[Session::name()]=$cookie;self::assertFalse(Session::valid($r));self::assertFalse(Store::permits($id,'personalized_pod','manage_digiforge'));self::assertTrue(WP_Session_Tokens::get_instance($id)->verify($token));
 }
 public function testEmptyInitialAssignmentIsRejected():void{
  PortalAccessFixture::enroll();$id=self::factory()->user->create(['role'=>'administrator']);self::assertWPError(Store::change($id,'Operator',['personalized_pod'=>[]],true,0,wp_generate_uuid4()));self::assertNull(Store::read($id));
 }
 public function testEmptyShopListCannotCreateAnUnauditedOwnerlessProfile():void {
  $id=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($id);
  self::assertWPError(\DigiForge\PortalAccess\Store::change($id,'Admin',[],true,0,wp_generate_uuid4()));
  self::assertNull(\DigiForge\PortalAccess\Store::read($id));
 }

}
