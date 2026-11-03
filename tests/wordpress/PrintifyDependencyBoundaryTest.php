<?php
declare(strict_types=1);
use DigiForge\POD\PrintifyResearchDependencies;
use DigiForge\POD\PrintifyResearchGateway;
use DigiForge\REST\PrintifyResearchController;

final class PrintifyDependencyBoundaryTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        foreach(['manage_digiforge','manage_digiforge_pod','manage_digiforge_research','manage_digiforge_connections'] as $cap)wp_get_current_user()->add_cap($cap);
        (new PrintifyResearchController())->register();do_action('rest_api_init');
    }
    public function testFixedRoutesAndNonceAreRequiredWithoutIssuingAnyGrant(): void
    {
        $base='/digiforge/v1/shops/personalized_pod/printify-research/readiness';
        $r=new WP_REST_Request('GET',$base);$denied=rest_get_server()->dispatch($r);self::assertSame(403,$denied->get_status());
        $r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$response=rest_get_server()->dispatch($r);self::assertSame(200,$response->get_status());self::assertFalse($response->get_data()['external_execution_authorized']);
        foreach(['/digiforge/v1/shops/digital/printify-research/readiness',$base.'/extra'] as $route){$other=new WP_REST_Request('GET',$route);$other->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));self::assertSame(404,rest_get_server()->dispatch($other)->get_status());}
    }
    public function testUnintegratedDependencyStopsBeforeHttpOrGrantMutation(): void
    {
        if(class_exists(DigiForge\POD\PrintifyCatalogReadRepository::class))$this->markTestSkipped('Standalone main boundary test; pinned shared composition is tested separately.');
        global $wpdb;$before=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->options.' WHERE option_name LIKE %s',$wpdb->esc_like('df_catalog_read_').'%'));
        $calls=0;$intercept=static function()use(&$calls){$calls++;return new WP_Error('unexpected_http','Dependency tests forbid HTTP.');};add_filter('pre_http_request',$intercept);
        try{$result=(new PrintifyResearchGateway())->collect(str_repeat('a',64),0,'dependency-denial-only');self::assertWPError($result);self::assertSame('pod_research_pipeline_unavailable',$result->get_error_code());self::assertSame(0,$calls);}
        finally{remove_filter('pre_http_request',$intercept);}
        $after=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->options.' WHERE option_name LIKE %s',$wpdb->esc_like('df_catalog_read_').'%'));self::assertSame($before,$after);
        $report=PrintifyResearchDependencies::inspect();self::assertFalse($report['integration_ready']);self::assertFalse($report['portal_controls_ready']);
    }
    public function testRegisteredRoutePatternsMatchTheOwnerHandoff(): void
    {
        $registered=rest_get_server()->get_routes();
        foreach(PrintifyResearchDependencies::routes() as $route){$matches=[];foreach(array_keys($registered) as $pattern)if(preg_match('@^'.$pattern.'$@i',$route['route']))$matches[]=$pattern;self::assertCount(1,$matches);}
    }
}
