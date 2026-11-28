<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Portal\PolicyEvidencePanel;
final class PortalPolicyEvidenceAcceptanceTest extends WP_UnitTestCase {
 protected function setUp():void {parent::setUp();DigiForge\Core\Activator::activate();Settings::protectProduction();ShopAccessFixture::reset();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));ShopAccessFixture::authorizeActor(get_current_user_id(),['digital','personalized_pod','standard_pod','jewelry'],DigiForge\Core\Capabilities::ALL);}
 private function html(string $shop):string{ob_start();PolicyEvidencePanel::render($shop);return (string)ob_get_clean();}
 public function testConfiguredStagesCannotOverrideGlobalCeilingsAndViewDoesNotWrite():void {
  global $wpdb;foreach(['research','ai','product_development','etsy_draft','printify','gelato','etsy_publish','order_automation'] as $cap)self::assertTrue(Settings::set($cap,true));$stored=$wpdb->get_results('SELECT * FROM '.Tables::settings().' ORDER BY setting_key',ARRAY_A);
  self::assertNotEmpty($stored);self::assertSame('', $wpdb->last_error);$data=PolicyEvidencePanel::snapshot('digital');self::assertSame('AVAILABLE',$data['query_state']);self::assertTrue($data['controls']['stop_all']);self::assertFalse($data['controls']['automation_armed']);self::assertTrue($data['controls']['externally_locked']);self::assertCount(8,$data['stages']);
  foreach($data['stages'] as $stage){self::assertTrue($stage['configured']);self::assertFalse($stage['approved_activation']);self::assertFalse($stage['effective_enabled']);self::assertSame(Settings::is_enabled($stage['capability']),$stage['effective_enabled']);}
  $old=$_GET;try{$_GET=['df_view'=>'settings','df_shop'=>'digital'];$portal=(new DigiForge\Portal\Portal())->render();self::assertStringContainsString('Non-finance activation ladder and global safety ceilings',$portal);$directory=getenv('DF_PORTAL_FIXTURE_DIR');if(is_string($directory)&&$directory!=='')self::assertSame(strlen($portal),file_put_contents($directory.'/'.'portal-settings--digital.html',$portal));}finally{$_GET=$old;}
  $html=$this->html('digital');self::assertStringContainsString('aria-label="Non-finance activation ladder"',$html);self::assertStringContainsString('External execution authority: NO',$html);self::assertStringNotContainsString('<form',$html);self::assertSame($stored,$wpdb->get_results('SELECT * FROM '.Tables::settings().' ORDER BY setting_key',ARRAY_A));
 }
 public function testMissingCorruptAndFailedReadsAreUnavailableNotCertifiedOff():void {
  global $wpdb;$table=Tables::settings();$wpdb->update($table,['setting_value'=>'"false"'],['setting_key'=>'printify']);self::assertSame('UNAVAILABLE',PolicyEvidencePanel::snapshot('digital')['query_state']);self::assertStringContainsString('Activation policy evidence unavailable',$this->html('digital'));
  $wpdb->update($table,['setting_value'=>'false'],['setting_key'=>'printify']);$wpdb->delete($table,['setting_key'=>'printify_activation_authorized']);self::assertSame('UNAVAILABLE',PolicyEvidencePanel::snapshot('digital')['query_state']);
  $filter=static fn(string $sql):string=>str_contains($sql,'SELECT setting_key,setting_value,setting_type FROM '.$table)?'SELECT * FROM missing_activation_policy_fixture':$sql;add_filter('query',$filter);try{self::assertSame('UNAVAILABLE',PolicyEvidencePanel::snapshot('digital')['query_state']);}finally{remove_filter('query',$filter);}
 }
 public function testAssignmentsAreCheckedBeforePrivateConfigurationRead():void {
  ShopAccessFixture::reset();ShopAccessFixture::authorizeActor(get_current_user_id(),['digital'],['manage_digiforge']);$queries=0;$filter=static function(string $sql)use(&$queries):string{if(str_contains($sql,'SELECT setting_key,setting_value,setting_type'))++$queries;return $sql;};add_filter('query',$filter);
  try{foreach(['jewelry','all','unknown_shop'] as $shop)self::assertSame('UNAVAILABLE',PolicyEvidencePanel::snapshot($shop)['query_state']);self::assertSame(0,$queries);self::assertSame('AVAILABLE',PolicyEvidencePanel::snapshot('digital')['query_state']);self::assertSame(1,$queries);}finally{remove_filter('query',$filter);}
 }
}
