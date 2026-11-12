<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
final class Gate2IntegrationTest extends WP_UnitTestCase {
 protected function setUp():void{parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();ShopAccessFixture::reset();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));}
 public function testResearchMountIsAvailableWithoutSeparateApi():void{ShopAccessFixture::authorizeActor(get_current_user_id(),['personalized_pod'],DigiForge\Core\Capabilities::ALL);$_GET=['df_view'=>'research','df_shop'=>'personalized_pod'];$m=new ReflectionMethod(DigiForge\Portal\Portal::class,'research');$m->setAccessible(true);ob_start();try{$m->invoke(new DigiForge\Portal\Portal());$html=ob_get_contents();}finally{ob_end_clean();$_GET=[];}self::assertStringContainsString('id="df-commercial-research"',$html);}
 public function testUnassignedAdministratorCannotCreatePodResearch():void{$input=json_decode(file_get_contents(__DIR__.'/../fixtures/research/commercial-research.json'),true);self::assertWPError((new DigiForge\Research\CommercialResearchRepository())->create($input['research']));}
 public function testUninstallDefaultPreservesDataAndNoDdlIsIssued():void{if(!defined('WP_UNINSTALL_PLUGIN'))define('WP_UNINSTALL_PLUGIN',true);delete_option('digiforge_cleanup_on_uninstall');$ddl=[];$observe=static function($sql)use(&$ddl){if(preg_match('/^\s*(DROP|DELETE|ALTER|TRUNCATE)/i',$sql))$ddl[]=$sql;return $sql;};add_filter('query',$observe);try{require dirname(__DIR__,2).'/uninstall.php';}finally{remove_filter('query',$observe);}self::assertSame([],$ddl);self::assertTrue(DigiForge\Core\Settings::safety_locked());}
 public function testRepeatedLocalInitializationPreservesSchemaAndSentinel():void{update_option('gate2_synthetic_update_sentinel','preserve');DigiForge\Core\Activator::activate();DigiForge\Core\Activator::activate();self::assertSame('preserve',get_option('gate2_synthetic_update_sentinel'));self::assertSame(25,(int)get_option('digiforge_db_schema_version'));self::assertTrue(DigiForge\Core\Settings::safety_locked());}
 public static function apiModes():array{return [['ABSENT'],['INCOMPATIBLE_STUB_V999']];}
 /** @dataProvider apiModes */
 public function testCompleteHeldJourneyDoesNotConsumeOptionalApi(string $mode):void{
  require_once __DIR__.'/Day4PortalWorkflowTest.php';ShopAccessFixture::authorizeActor(get_current_user_id(),['personalized_pod'],DigiForge\Core\Capabilities::ALL);
  $called=0;$probe=static function()use(&$called){++$called;return ['protocol'=>'unsupported/v999','shop_key'=>'digital','operations'=>['order.submit']];};
  if($mode!=='ABSENT'){update_option('active_plugins',['synthetic-incompatible-printify-api/plugin.php']);add_filter('digiforge_optional_printify_provider_v1',$probe);}
  try{$test=new Day4PortalWorkflowTest('testMugAndPosterCompleteThroughExistingPortalParserAndRender');$test->testMugAndPosterCompleteThroughExistingPortalParserAndRender();self::assertSame(0,$called);self::assertFalse((new DigiForge\Research\GovernedResearchProvider())->status()['available']);self::assertFalse((new DigiForge\Research\DesignFactoryRepository())->providerStatus()['available']);self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertTrue(DigiForge\Core\Settings::get('stop_all'));self::assertFalse(DigiForge\Core\Settings::get('automation_armed'));}finally{remove_filter('digiforge_optional_printify_provider_v1',$probe);update_option('active_plugins',[]);}
 }
}
