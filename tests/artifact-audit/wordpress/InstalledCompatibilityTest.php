<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyResearchAccessFixture.php';
final class InstalledCompatibilityTest extends WP_UnitTestCase {
 protected function setUp():void{parent::setUp();PrintifyResearchAccessFixture::reset();}
 public function testNativeSignedAssignmentsThroughActualGlobalRestGuard():void{
  $http=0;$block=static function()use(&$http){++$http;return new WP_Error('test_no_egress');};add_filter('pre_http_request',$block);
  try{
   do_action('rest_api_init');$id=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($id);
   $caps=['manage_digiforge','manage_digiforge_pod','manage_digiforge_research','manage_digiforge_connections'];foreach($caps as $cap)wp_get_current_user()->add_cap($cap);
   $request=new WP_REST_Request('GET','/digiforge/v1/shops/personalized_pod/printify-research/readiness');$request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
   self::assertSame(403,rest_do_request($request)->get_status());
   PrintifyResearchAccessFixture::assign($id,'digital',$caps);self::assertSame(403,rest_do_request($request)->get_status());
   PrintifyResearchAccessFixture::reset();PrintifyResearchAccessFixture::assign($id,'personalized_pod',$caps);
   self::assertSame(200,rest_do_request($request)->get_status());
   $request->set_param('shop_key','digital');self::assertSame(403,rest_do_request($request)->get_status());
   $request->set_param('shop_key',null);$request->set_header('X-WP-Nonce','invalid');self::assertSame(403,rest_do_request($request)->get_status());
   wp_set_current_user(0);self::assertContains(rest_do_request($request)->get_status(),[401,403]);self::assertSame(0,$http);
  }finally{remove_filter('pre_http_request',$block);}
 }
 public function testOnlyExactUrlsAndVerbsAreAdmitted():void{
  foreach(\DigiForge\POD\PrintifyResearchDependencies::routes() as $r){
   self::assertTrue(\DigiForge\POD\PrintifyResearchDependencies::matchesRoute($r['route'],$r['method']));
   foreach(['DELETE','PUT','PATCH'] as $method)self::assertFalse(\DigiForge\POD\PrintifyResearchDependencies::matchesRoute($r['route'],$method));
   self::assertSame([],\DigiForge\Security\ShopAccessRestGuard::capabilitySets($r['route'],'DELETE'));
   self::assertFalse(\DigiForge\POD\PrintifyResearchDependencies::matchesRoute(str_replace('personalized_pod','digital',$r['route']),$r['method']));
  }
  self::assertNull(\DigiForge\Security\ShopAccessAdminGuard::scopeForAction('digiforge_pod_research_step_extra'));
  self::assertNull(\DigiForge\Security\ShopAccessAdminGuard::scopeForAction('digiforge_integration_update'));
 }
 public function testFeatureAdminAdmissionRequiresSignedExactShopAssignment():void{
  $id=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($id);foreach(['manage_digiforge','manage_digiforge_pod'] as $cap)wp_get_current_user()->add_cap($cap);
  $die=static fn()=>static function(){throw new RuntimeException('denied');};add_filter('wp_die_handler',$die);
  try{(new \DigiForge\Security\ShopAccessAdminGuard())->action('admin_post_digiforge_pod_intelligence_export');self::fail('Unassigned actor admitted');}catch(RuntimeException $e){self::assertSame('denied',$e->getMessage());}finally{remove_filter('wp_die_handler',$die);}
  PrintifyResearchAccessFixture::assign($id,'personalized_pod',['manage_digiforge','manage_digiforge_pod']);
  (new \DigiForge\Security\ShopAccessAdminGuard())->action('admin_post_digiforge_pod_intelligence_export');self::assertTrue(true);
 }
 public function testGovernedTransportAndFreshBridgeFailClosedWithoutAuthority():void{
  wp_set_current_user(0);$http=0;$client=new \DigiForge\POD\PrintifyCatalogClient(static function()use(&$http){++$http;return [];});
  $repo=new \DigiForge\POD\PrintifyCatalogReadRepository($client);
  self::assertWPError($repo->research(str_repeat('a',64),0,'synthetic_attempt',0));
  self::assertSame('catalog_read_cache_unsupported',$repo->research(str_repeat('a',64),0,'synthetic_attempt',1)->get_error_code());
  foreach([['kind'=>'providers','blueprint_id'=>5],['kind'=>'size_guide','blueprint_id'=>5],['kind'=>'shipping','blueprint_id'=>5,'provider_id'=>2]] as $r)self::assertWPError($client->scopedRead('synthetic-not-a-credential',$r));
  self::assertSame(0,$http);
 }
}
