<?php
declare(strict_types=1);
final class PortalBrowserFixtureTest extends WP_UnitTestCase {
 public function testCaptureSafeLocalViews():void {
  $directory=getenv('DF_PORTAL_BROWSER_FIXTURE_DIR');if(!is_string($directory)||$directory===''||!is_dir($directory)){self::fail('An existing disposable DF_PORTAL_BROWSER_FIXTURE_DIR is required.');}
  DigiForge\Core\Activator::activate();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));DigiForge\Core\Settings::protectProduction();
  $p=(new DigiForge\AI\ShopAiGovernanceRepository())->savePolicy(['shop_key'=>'digital','currency'=>'USD','template_cap'=>['limit'=>8,'duration_seconds'=>604800,'anchor_utc'=>'2026-01-05 00:00:00']]);self::assertFalse(is_wp_error($p));$before=DigiForge\Core\Settings::get('stop_all',true);
  $get=$_GET;try {foreach(['ai_budget','research','businesses','system'] as $view){$_GET=['df_view'=>$view,'df_shop'=>'digital'];$html=(new DigiForge\Portal\Portal())->render();self::assertStringContainsString('df-portal-shell',$html);self::assertNotFalse(file_put_contents($directory.'/portal-'.$view.'.html',$html));}}finally{$_GET=$get;}
  self::assertSame($before,DigiForge\Core\Settings::get('stop_all',true));
 }
}
