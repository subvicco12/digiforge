<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
require_once __DIR__.'/PortalActionAcceptanceFixture.php';
use DigiForge\Portal\NotificationPreferences;
final class PortalNotificationAcceptanceTest extends WP_UnitTestCase {
 protected function setUp():void {parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();ShopAccessFixture::reset();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));ShopAccessFixture::authorizeActor(get_current_user_id(),['digital','personalized_pod','standard_pod','jewelry'],DigiForge\Core\Capabilities::ALL);}
 private function html(string $message,bool $error=false):string {$get=$_GET;try{$_GET=['df_view'=>'settings','df_message'=>$message,'df_error'=>$error?'1':'0'];return (new DigiForge\Portal\Portal())->render();}finally{$_GET=$get;}}
 public function testNativePreferenceChangesActualRoutineNoticesOnlyForCurrentOperator():void {
  $first=get_current_user_id();$second=self::factory()->user->create(['role'=>'administrator']);ShopAccessFixture::authorizeActor($second,['digital','personalized_pod','standard_pod','jewelry'],['manage_digiforge']);self::assertStringContainsString('routine-visible-marker',$this->html('routine-visible-marker'));PortalActionAcceptanceFixture::invoke('NOTIFICATION_PREFERENCES_ACTION',[]);self::assertSame('0',get_user_meta($first,NotificationPreferences::META_KEY,true));self::assertStringNotContainsString('routine-hidden-marker',$this->html('routine-hidden-marker'));self::assertStringContainsString('error-visible-marker',$this->html('error-visible-marker',true));self::assertStringContainsString('STOP ALL: ON',$this->html(''));
  wp_set_current_user($second);self::assertStringContainsString('second-operator-visible',$this->html('second-operator-visible'));self::assertSame('',get_user_meta($second,NotificationPreferences::META_KEY,true));wp_set_current_user($first);PortalActionAcceptanceFixture::invoke('NOTIFICATION_PREFERENCES_ACTION',['show_success_notifications'=>'1']);self::assertStringContainsString('restored-visible-marker',$this->html('restored-visible-marker'));self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertTrue(DigiForge\Core\Settings::safety_locked());
 }
 public function testFailedPreferenceWriteIsAnErrorAndLimitedUsersCannotChangeIt():void {
  $id=get_current_user_id();update_user_meta($id,NotificationPreferences::META_KEY,'1');$fail=static fn()=>false;add_filter('update_user_metadata',$fail);try{PortalActionAcceptanceFixture::invoke('NOTIFICATION_PREFERENCES_ACTION',[],true);self::assertSame('1',get_user_meta($id,NotificationPreferences::META_KEY,true));}finally{remove_filter('update_user_metadata',$fail);}
  wp_set_current_user(self::factory()->user->create(['role'=>'subscriber']));self::assertWPError((new NotificationPreferences())->save(false));self::assertSame('',get_user_meta(get_current_user_id(),NotificationPreferences::META_KEY,true));
 }
}
