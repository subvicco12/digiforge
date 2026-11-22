<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyResearchAccessFixture.php';

final class PrintifySharedCoreIntegrationTest extends WP_UnitTestCase
{
    public function setUp(): void {parent::setUp();PrintifyResearchAccessFixture::reset();(new \DigiForge\Portal\ProductIntelligencePanel())->register();}
    public function testPanelRegistersCatalogAndResearchRoutesWithoutCumulativeGuards(): void
    {
        do_action('rest_api_init');$routes=rest_get_server()->get_routes();
        self::assertArrayHasKey('/digiforge/v1/printify/catalog-read/grants',$routes);
        self::assertArrayHasKey('/digiforge/v1/shops/personalized_pod/printify-research/readiness',$routes);
        self::assertFalse(class_exists(\DigiForge\Security\ShopAccessRestGuard::class));
        $report=\DigiForge\POD\PrintifyResearchDependencies::inspect();
        self::assertTrue($report['integration_ready']);self::assertTrue($report['size_guide_grant_scope_supported']);self::assertFalse($report['external_execution_authorized']);
    }
    public function testRealRestDispatchDeniesOtherShopAndUnassignedAdminBeforeHttp(): void
    {
        $id=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($id);
        $caps=['manage_digiforge','manage_digiforge_pod','manage_digiforge_research','manage_digiforge_connections'];foreach($caps as $cap)wp_get_current_user()->add_cap($cap);
        $http=0;$tripwire=static function()use(&$http){++$http;throw new RuntimeException('HTTP forbidden');};add_filter('pre_http_request',$tripwire);
        try{
            do_action('rest_api_init');
            $request=new WP_REST_Request('GET','/digiforge/v1/shops/personalized_pod/printify-research/readiness');$request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
            self::assertSame(403,rest_do_request($request)->get_status());
            PrintifyResearchAccessFixture::assign($id,'digital',$caps);self::assertSame(403,rest_do_request($request)->get_status());
            PrintifyResearchAccessFixture::reset();PrintifyResearchAccessFixture::assign($id,'personalized_pod',$caps);
            self::assertSame(200,rest_do_request($request)->get_status());
            $wrong=new WP_REST_Request('GET','/digiforge/v1/shops/digital/printify-research/readiness');self::assertSame(404,rest_do_request($wrong)->get_status());
            $request->set_header('X-WP-Nonce','invalid');self::assertSame(403,rest_do_request($request)->get_status());
            self::assertSame(0,$http);
        }finally{remove_filter('pre_http_request',$tripwire);}
    }
    public function testPrivilegedUnassignedAndForeignShopCannotRevokeCatalogAuthority(): void
    {
        $id=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($id);
        $caps=['manage_digiforge','manage_digiforge_pod','manage_digiforge_research','manage_digiforge_connections'];foreach($caps as $cap)wp_get_current_user()->add_cap($cap);
        do_action('rest_api_init');
        foreach(['unassigned','digital'] as $assignment){
            if($assignment==='digital')PrintifyResearchAccessFixture::assign($id,'digital',$caps);
            foreach(['/revoke','/safety-exception/revoke'] as $suffix){
              foreach(['printify','PRINTIFY'] as $literal){
                $request=new WP_REST_Request('POST','/digiforge/v1/'.$literal.'/catalog-read/'.str_repeat('a',64).$suffix);$request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
                $response=rest_do_request($request);self::assertSame(403,$response->get_status());self::assertSame('pod_research_catalog_scope',$response->get_data()['code']);
              }
            }
            $head=new WP_REST_Request('HEAD','/digiforge/v1/printify/catalog-read/'.str_repeat('a',64));$head->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
            $response=rest_do_request($head);self::assertSame(403,$response->get_status());self::assertSame('pod_research_catalog_scope',$response->get_data()['code']);
        }
    }
    public function testProviderAndSizeGuideScopesStayBoundedAndUnapproved(): void
    {
        $grant=['capability'=>'printify.catalog.read','integration_id'=>1,'account_id'=>2,'business_id'=>'digicraftifygoods','store_id'=>'shop2','product_program'=>'PERSONALIZED_POD','expires_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+600),'max_requests'=>10,'requests'=>[['kind'=>'providers','blueprint_id'=>5],['kind'=>'size_guide','blueprint_id'=>5]]];
        $normalized=\DigiForge\POD\PrintifyCatalogReadContract::normalize($grant,time());self::assertSame($grant,$normalized);
        self::assertSame('/v1/catalog/blueprints/5/print_providers.json',\DigiForge\POD\PrintifyCatalogReadContract::endpoint($grant['requests'][0]));
        self::assertSame('/v1/catalog/blueprints/5/size_guide.json',\DigiForge\POD\PrintifyCatalogReadContract::endpoint($grant['requests'][1]));
        $calls=0;$client=new \DigiForge\POD\PrintifyCatalogClient(static function()use(&$calls){++$calls;throw new RuntimeException('Forbidden');});
        foreach($grant['requests'] as $scope)self::assertWPError($client->scopedRead('synthetic-noncredential',$scope));self::assertSame(0,$calls);
    }
}
