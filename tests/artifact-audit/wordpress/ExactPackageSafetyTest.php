<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyResearchAccessFixture.php';
final class ExactPackageSafetyTest extends WP_UnitTestCase {
 protected function setUp():void{parent::setUp();PrintifyResearchAccessFixture::reset();}
 public function testSettingsRemainFailClosedAndNoFeatureMigrationIsRegistered():void{
  self::assertTrue(\DigiForge\Core\Settings::get('stop_all',true));
  self::assertFalse(\DigiForge\Core\Settings::get('automation_armed',false));
  self::assertTrue(\DigiForge\Core\Settings::safety_locked());
  foreach(['printify','etsy_publish','order_automation','gst_automation'] as $switch)self::assertFalse(\DigiForge\Core\Settings::is_enabled($switch));
  self::assertSame('23',DIGIFORGE_DB_VERSION);self::assertSame(getenv('EXACT_PLUGIN_VERSION')?:'1.0.103.1',DIGIFORGE_VERSION);
 }
 public function testActualInstalledPortalMountPreservesSafetyOptionsAndNoEgress():void{
  $id=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($id);$caps=['manage_digiforge','manage_digiforge_pod','manage_digiforge_research','manage_digiforge_connections'];foreach($caps as $cap)wp_get_current_user()->add_cap($cap);PrintifyResearchAccessFixture::assign($id,'personalized_pod',$caps);
  (new \DigiForge\Portal\PrintifyProductIntelligenceFeature())->register();
  $http=0;$deny=static function()use(&$http){++$http;return new WP_Error('certification_no_egress');};add_filter('pre_http_request',$deny);
  $before=[\DigiForge\Core\Settings::get('stop_all',true),\DigiForge\Core\Settings::get('automation_armed',false),\DigiForge\Core\Settings::get('activation_authorized',false)];
  try{$_GET=['df_shop'=>'personalized_pod'];ob_start();(new ReflectionMethod(\DigiForge\Portal\Portal::class,'personalizedPodSummary'))->invoke(new \DigiForge\Portal\Portal());$html=ob_get_clean();self::assertStringContainsString('Product Intelligence',$html);self::assertStringContainsString('Buyer-specific artwork',$html);self::assertSame(0,$http);
   self::assertSame($before,[\DigiForge\Core\Settings::get('stop_all',true),\DigiForge\Core\Settings::get('automation_armed',false),\DigiForge\Core\Settings::get('activation_authorized',false)]);
  }finally{$_GET=[];remove_filter('pre_http_request',$deny);}
 }
 public function testActualFeatureRouteRegistrationIsOncePerNormalRequest():void{
  $feature=new \DigiForge\Portal\PrintifyProductIntelligenceFeature();self::assertTrue($feature->register());self::assertTrue($feature->register());
  $routes=rest_get_server()->get_routes();$count=0;
  foreach($routes as $route=>$handlers){if(!str_starts_with($route,'/digiforge/v1/shops/personalized_pod/printify-research'))continue;$owned=0;foreach($handlers as $handler){$cb=is_array($handler)?($handler['callback']??null):null;if(is_array($cb)&&$cb[0] instanceof \DigiForge\REST\PrintifyResearchController)$owned++;}self::assertSame(1,$owned,$route);$count++;}
  self::assertSame(6,$count);
 }
}
