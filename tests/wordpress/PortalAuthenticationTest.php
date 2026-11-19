<?php
use DigiForge\PortalAccess\{Authentication,Feature,Session,Store};
require_once __DIR__.'/fixtures/PortalAccessFixture.php';
final class PortalAuthenticationTest extends WP_UnitTestCase {
 protected function setUp():void{parent::setUp();PortalAccessFixture::reset();}
 protected function tearDown():void{global $wpdb;$wpdb->query('COMMIT');PortalAccessFixture::reset();$wpdb->query('COMMIT');$wpdb->query('START TRANSACTION');parent::tearDown();}
 private function emailLogin(string $email,string $return=''):array|WP_Error {
  $mail=[];$capture=static function($pre,$p)use(&$mail){$mail[]=$p;return true;};add_filter('pre_wp_mail',$capture,PHP_INT_MAX,2);
  try{$r=Authentication::process(['email'=>$email,'return_to'=>$return,'challenge'=>Authentication::browserChallenge(),'_wpnonce'=>wp_create_nonce(Authentication::ACTION)]);if(is_wp_error($r))return $r;preg_match('/\b([0-9]{8})\b/',end($mail)['message'],$m);return Authentication::process(['step'=>'verify','selector'=>$r['selector'],'code'=>$m[1],'challenge'=>Authentication::browserChallenge(),'_wpnonce'=>wp_create_nonce(Authentication::ACTION)]);}finally{remove_filter('pre_wp_mail',$capture,PHP_INT_MAX);}
 }
 private function ready():void{self::assertTrue(class_exists(Authentication::class),'Portal authentication is not implemented');}
 public function testDedicatedLoginAndAccountNavigationUseWordPressRecoveryAndNonceLogout():void{$this->ready();$html=Authentication::render();self::assertStringContainsString('DigiForge',$html);self::assertStringContainsString('lostpassword',$html);self::assertStringContainsString('_wpnonce',$html);PortalAccessFixture::owner();$bar=Feature::accountBar();self::assertStringContainsString('action=logout',$bar);self::assertStringContainsString('_wpnonce',$bar);}
 public function testLoginRejectsNonceAndExternalRedirect():void{$this->ready();self::assertWPError(Authentication::process(['login'=>'x','password'=>'x','_wpnonce'=>'wrong']));self::assertSame(home_url('/'),Authentication::safeReturn('https://attacker.invalid/'));self::assertSame(home_url('/'),Authentication::safeReturn('//attacker.invalid/'));}
 public function testExistingWordPressAccountCanAuthenticateWithoutCreatingOwner():void{$this->ready();$id=self::factory()->user->create(['user_login'=>'portal_auth_fixture','user_pass'=>'syntheticFixturePass123!','role'=>'subscriber','user_email'=>'auth-fixture@example.invalid']);wp_set_current_user(0);$r=$this->emailLogin('auth-fixture@example.invalid');self::assertNotWPError($r);self::assertSame($id,$r['actor_id']);self::assertNull(Store::read($id));self::assertWPError(\DigiForge\PortalAccess\Owner::require('personalized_pod'));}
 public function testLoginIsBoundedAndDoesNotEnumerateAccounts():void{$this->ready();wp_set_current_user(0);$p=['email'=>'absent-fixture@example.invalid','challenge'=>Authentication::browserChallenge(),'_wpnonce'=>wp_create_nonce(Authentication::ACTION)];for($i=0;$i<3;$i++)self::assertNotWPError(Authentication::process($p));$r=Authentication::process($p);self::assertWPError($r);self::assertSame(429,$r->get_error_data()['status']);}
 public function testSharedGuestNonceWithoutBrowserChallengeDoesNotReserveOrAttemptLogin():void{$this->ready();wp_set_current_user(0);global $wpdb;$before=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like('digiforge_portal_rate_').'%'));Authentication::process(['login'=>'missing_challenge_fixture','password'=>'bad','_wpnonce'=>wp_create_nonce(Authentication::ACTION)]);$after=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like('digiforge_portal_rate_').'%'));self::assertSame($before,$after);}

 public function testPendingInviteCanLoginToAcceptanceWithoutReceivingPortalSession():void{$this->ready();PortalAccessFixture::enroll();$id=self::factory()->user->create(['user_login'=>'pending_login_fixture','user_pass'=>'syntheticPendingPass123!','role'=>'administrator','user_email'=>'pending-fixture@example.invalid']);$invite=\DigiForge\PortalAccess\Invitations::create($id,'Viewer','personalized_pod',['research'],time()+600,wp_generate_uuid4());self::assertNotWPError($invite);wp_set_current_user(0);$_COOKIE=[];$url=add_query_arg('df_view','invitation',home_url('/'));$r=$this->emailLogin('pending-fixture@example.invalid',$url);self::assertNotWPError($r);self::assertSame($url,$r['return_to']);self::assertFalse(Session::valid(Store::read($id)));self::assertNotWPError(\DigiForge\PortalAccess\Invitations::accept($invite['token'],wp_generate_uuid4()));self::assertTrue(Session::valid(Store::read($id)));}

 public function testWordPressLogoutDestroysNativeTokenAndPortalSession():void {
  $id=PortalAccessFixture::enroll();$token=wp_get_session_token();self::assertTrue(WP_Session_Tokens::get_instance($id)->verify($token));self::assertTrue(Session::valid(Store::read($id)));
  $query=[];parse_str((string)wp_parse_url(html_entity_decode(wp_logout_url(Authentication::loginUrl()),ENT_QUOTES,'UTF-8'),PHP_URL_QUERY),$query);self::assertNotFalse(wp_verify_nonce($query['_wpnonce'],'log-out'));
  wp_logout();self::assertSame(0,get_current_user_id());self::assertFalse(WP_Session_Tokens::get_instance($id)->verify($token));self::assertArrayNotHasKey(Session::name(),$_COOKIE);self::assertFalse(Session::valid(Store::read($id)));
 }
 public function testWordPressRecoveryProducesValidResetKeyWithoutSendingEmailOrGrantingAuthority():void {
  $id=self::factory()->user->create(['user_login'=>'portal_recovery_fixture','user_email'=>'recovery@example.invalid','role'=>'subscriber']);$key=null;$mail=0;
  $capture=static function($login,$value)use(&$key){if($login==='portal_recovery_fixture')$key=$value;};$deny=static function($pre,$attributes)use(&$mail){$mail++;return true;};
  add_action('retrieve_password_key',$capture,10,2);add_filter('pre_wp_mail',$deny,PHP_INT_MAX,2);
  try{self::assertTrue(retrieve_password('portal_recovery_fixture'));self::assertSame(1,$mail);self::assertIsString($key);$user=check_password_reset_key($key,'portal_recovery_fixture');self::assertInstanceOf(WP_User::class,$user);self::assertSame($id,$user->ID);self::assertNull(Store::read($id));}
  finally{remove_action('retrieve_password_key',$capture,10);remove_filter('pre_wp_mail',$deny,PHP_INT_MAX);$key=null;}
 }

}
