<?php
use DigiForge\PortalAccess\{Authentication,EmailAuthentication,OwnerBootstrap,Owner,Store,Session};
require_once __DIR__.'/fixtures/PortalAccessFixture.php';
final class PortalEmailAuthenticationTest extends WP_UnitTestCase {
 private array $mail=[];
 private $mailer;
 protected function setUp():void {parent::setUp();PortalAccessFixture::reset();$this->mailer=function($pre,$mail){$this->mail[]=$mail;return true;};add_filter('pre_wp_mail',$this->mailer,PHP_INT_MAX,2);}
 protected function tearDown():void {remove_filter('pre_wp_mail',$this->mailer,PHP_INT_MAX);global $wpdb;$wpdb->query('COMMIT');PortalAccessFixture::reset();$wpdb->query('COMMIT');$wpdb->query('START TRANSACTION');parent::tearDown();}
 private function request(string $email):array|WP_Error {return Authentication::process(['email'=>$email,'challenge'=>Authentication::browserChallenge(),'_wpnonce'=>wp_create_nonce(Authentication::ACTION),'return_to'=>home_url('/')]);}
 private function verify(array $r,string $code):array|WP_Error{return Authentication::process(['step'=>'verify','selector'=>$r['selector'],'code'=>$code,'challenge'=>Authentication::browserChallenge(),'_wpnonce'=>wp_create_nonce(Authentication::ACTION)]);}
 private function code():string {preg_match('/\b([0-9]{8})\b/',end($this->mail)['message'],$m);return $m[1]??'';}
 public function testEmailUiReplacesPasswordAndExistingAccountLogsInWithoutAuthority():void {
  self::assertTrue(class_exists(EmailAuthentication::class),'Email verification is missing');$html=Authentication::render();self::assertStringNotContainsString('name="password"',$html);self::assertStringContainsString('name="email"',$html);
  $id=self::factory()->user->create(['user_email'=>'email-fixture@example.invalid','role'=>'subscriber']);wp_set_current_user(0);$r=$this->request('email-fixture@example.invalid');self::assertNotWPError($r);self::assertCount(1,$this->mail);self::assertSame(0,get_current_user_id());$code=$this->code();self::assertMatchesRegularExpression('/^[0-9]{8}$/',$code);
  global $wpdb;$raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",EmailAuthentication::key($r['selector'])));self::assertStringNotContainsString($code,$raw);
  $verified=$this->verify($r,$code);self::assertNotWPError($verified);self::assertSame($id,get_current_user_id());self::assertNull(Store::read($id));self::assertWPError(Owner::require('personalized_pod'));self::assertTrue(EmailAuthentication::recent($id));wp_set_current_user(0);self::assertWPError($this->verify($r,$code));
 }
 public function testUnknownAccountHasSameGenericResponseAndNoMail():void {self::assertTrue(class_exists(EmailAuthentication::class));$r=$this->request('nobody@example.invalid');self::assertNotWPError($r);self::assertSame(['selector','verification_required','return_to'],array_keys($r));self::assertCount(0,$this->mail);self::assertWPError($this->verify($r,'12345678'));}
 public function testBrowserBindingWrongCodesAndPasswordChangeFailClosed():void {
  self::assertTrue(class_exists(EmailAuthentication::class));$id=self::factory()->user->create(['user_email'=>'bound@example.invalid']);$r=$this->request('bound@example.invalid');$code=$this->code();$cookies=$_COOKIE;$_COOKIE=[];self::assertWPError($this->verify($r,$code));$_COOKIE=$cookies;
  for($i=0;$i<5;$i++)self::assertWPError($this->verify($r,$code==='00000000'?'00000001':'00000000'));self::assertWPError($this->verify($r,$code));
  $r=$this->request('bound@example.invalid');$code=$this->code();wp_set_password('changed-password-fixture',$id);self::assertWPError($this->verify($r,$code));self::assertSame(0,get_current_user_id());
 }
 public function testExpiryMailFailureDisabledProfileAndRequestLimits():void {
  self::assertTrue(class_exists(EmailAuthentication::class));self::factory()->user->create(['user_email'=>'expire@example.invalid']);$r=$this->request('expire@example.invalid');$code=$this->code();global $wpdb;$key=EmailAuthentication::key($r['selector']);$s=json_decode($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key)),true);$s['expires']=time()-1;$wpdb->update($wpdb->options,['option_value'=>wp_json_encode($s)],['option_name'=>$key]);self::assertWPError($this->verify($r,$code));
  $this->request('expire@example.invalid');$this->request('expire@example.invalid');self::assertWPError($this->request('expire@example.invalid'));
  remove_filter('pre_wp_mail',$this->mailer,PHP_INT_MAX);$deny=static fn()=>false;add_filter('pre_wp_mail',$deny,PHP_INT_MAX);self::factory()->user->create(['user_email'=>'failure@example.invalid']);$r=$this->request('failure@example.invalid');self::assertNotWPError($r);self::assertNull($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",EmailAuthentication::key($r['selector']))));remove_filter('pre_wp_mail',$deny,PHP_INT_MAX);
 }
 public function testPasswordSubmissionAndMissingBrowserChallengeAreRejected():void {self::assertTrue(class_exists(EmailAuthentication::class));self::assertWPError(Authentication::process(['login'=>'admin','password'=>'unused','challenge'=>Authentication::browserChallenge(),'_wpnonce'=>wp_create_nonce(Authentication::ACTION)]));self::assertWPError(Authentication::process(['email'=>'subvicco12@gmail.com','_wpnonce'=>wp_create_nonce(Authentication::ACTION)]));}
 public function testBootstrapCannotUseEmailOrWordPressAdministrationAlone():void {self::assertTrue(class_exists(OwnerBootstrap::class));$id=PortalAccessFixture::owner();self::assertWPError(OwnerBootstrap::enroll('bad-code',wp_generate_uuid4()));self::assertNull(Store::read($id));self::assertStringContainsString('one-time enrollment approved by the trusted server operator',OwnerBootstrap::render());}
 public function testDisabledOrRevokedAccountCannotUsePreviouslyDeliveredCode():void {
  $id=PortalAccessFixture::enroll();wp_set_current_user(0);$_COOKIE=[];$r=$this->request(Owner::EMAIL);$code=$this->code();PortalAccessFixture::login($id);$old=Store::read($id);$old['enabled']=false;$revision=$old['revision'];$old['revision']++;$old['generation']++;self::assertNotWPError(Store::save($old,$revision,wp_generate_uuid4()));wp_set_current_user(0);self::assertWPError($this->verify($r,$code));self::assertSame(0,get_current_user_id());
 }
 public function testVerifiedEmailLogoutInvalidatesBothSessions():void {
  $id=self::factory()->user->create(['user_email'=>'logout-email@example.invalid']);$r=$this->request('logout-email@example.invalid');self::assertNotWPError($this->verify($r,$this->code()));$token=wp_get_session_token();self::assertTrue(EmailAuthentication::recent($id));wp_logout();self::assertSame(0,get_current_user_id());self::assertFalse(WP_Session_Tokens::get_instance($id)->verify($token));self::assertFalse(EmailAuthentication::recent($id));self::assertArrayNotHasKey(Session::name(),$_COOKIE);
 }
 public function testLegacyShopAuthorityCannotBecomeGlobalEvenWithEmailAndSetupCode():void {
  if(!defined('DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SITE'))define('DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SITE',home_url('/'));
  if(!defined('DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SHA256'))define('DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SHA256',hash('sha256',str_repeat('a',64)));
  $existing=get_user_by('email',Owner::EMAIL);if($existing&&$existing->ID!==1)wp_update_user(['ID'=>$existing->ID,'user_email'=>'prior-owner-fixture@example.invalid']);wp_update_user(['ID'=>1,'user_email'=>Owner::EMAIL]);$u=get_userdata(1);$u->set_role('administrator');foreach(\DigiForge\Core\Capabilities::ALL as $c)$u->add_cap($c);PortalAccessFixture::sign(1);PortalAccessFixture::login(1);
  self::assertWPError(OwnerBootstrap::enroll(str_repeat('a',64),wp_generate_uuid4()));wp_set_current_user(0);$_COOKIE=[];$r=$this->request(Owner::EMAIL);self::assertNotWPError($this->verify($r,$this->code()));self::assertSame(1,get_current_user_id());
  self::assertWPError(OwnerBootstrap::enroll(str_repeat('b',64),wp_generate_uuid4()));PortalAccessFixture::sign(1,time()-1);self::assertWPError(OwnerBootstrap::enroll(str_repeat('a',64),wp_generate_uuid4()));PortalAccessFixture::sign(1);$records=\DigiForge\Security\ShopAccessAuthority::records();$conflict=$records[0];$conflict['actor_id']=999;SyntheticShopAuthority::write([$records[0],$conflict]);self::assertWPError(OwnerBootstrap::enroll(str_repeat('a',64),wp_generate_uuid4()));PortalAccessFixture::sign(1);
  $r=OwnerBootstrap::enroll(str_repeat('a',64),wp_generate_uuid4());self::assertWPError($r);self::assertNull(Store::read(1));self::assertWPError(Owner::require('personalized_pod'));self::assertWPError(Owner::require('digital'));self::assertWPError(OwnerBootstrap::enroll(str_repeat('a',64),wp_generate_uuid4()));
 }

 public function testCompetingTokenConsumptionCannotIssueASession():void {
  self::factory()->user->create(['user_email'=>'race-email@example.invalid']);$r=$this->request('race-email@example.invalid');$code=$this->code();global $wpdb;$key=EmailAuthentication::key($r['selector']);
  $race=null;$race=static function($sql)use(&$race,$wpdb,$key){if(str_starts_with($sql,'UPDATE')&&str_contains($sql,$key)){remove_filter('query',$race);$wpdb->delete($wpdb->options,['option_name'=>$key]);}return $sql;};add_filter('query',$race);
  try{self::assertWPError($this->verify($r,$code));self::assertSame(0,get_current_user_id());}finally{remove_filter('query',$race);}
 }

 public function testExistingWordPressSessionCanReverifyEmailAndRotatesItsToken():void {
  $id=PortalAccessFixture::owner();$old=wp_get_session_token();self::assertStringContainsString('name="email"',Authentication::render());$r=$this->request(Owner::EMAIL);self::assertNotWPError($this->verify($r,$this->code()));self::assertTrue(EmailAuthentication::recent($id));self::assertNotSame($old,wp_get_session_token());self::assertFalse(WP_Session_Tokens::get_instance($id)->verify($old));
 }

 public function testInterruptedOwnerActivationLeavesADisabledAuditedProfile():void {
  PortalAccessFixture::owner();ServerOwnerApprovalFixture::write();PortalAccessFixture::verifyEmail();
  global $wpdb;$prior=$wpdb->suppress_errors(true);$deny=static function($sql){return (str_starts_with($sql,'UPDATE')||str_starts_with($sql,'INSERT'))&&str_contains($sql,'digiforge_portal_profile_1')&&str_contains(str_replace(chr(92),'',$sql),'"enabled":true')? 'UPDATE nonexistent_owner_activation_fixture SET value=1':$sql;};add_filter('query',$deny);
  try{$result=OwnerBootstrap::enroll('',wp_generate_uuid4());}finally{remove_filter('query',$deny);$wpdb->suppress_errors($prior);}
  self::assertWPError($result);$profile=Store::read(1);self::assertIsArray($profile);self::assertFalse($profile['enabled']);self::assertWPError(Owner::require('personalized_pod'));self::assertWPError(\DigiForge\Security\ShopAccess::authorize('personalized_pod','manage_digiforge_settings',1));self::assertWPError(OwnerBootstrap::enroll(str_repeat('a',64),wp_generate_uuid4()));
  self::assertWPError(Owner::enroll('personalized_pod','administrator',\DigiForge\Core\Capabilities::ALL,time()+600,hash('sha256','synthetic1257'),0,wp_generate_uuid4()));self::assertFalse(Store::read(1)['enabled']);
 }

}
