<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

use DigiForge\Core\Capabilities;
use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Portal\CommercialResearchPanel;
use DigiForge\Portal\OpportunityDesignPanel;
use DigiForge\Portal\Portal;

/** Real signed admission and durable state; distinguish module effects from legacy portal checks. */
final class PersonalizedPodModuleIsolationTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();DigiForge\Core\Activator::activate();Settings::protectProduction();ShopAccessFixture::reset();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        ShopAccessFixture::authorizeActor(get_current_user_id(),['digital'],Capabilities::ALL);
    }
    private function observe(callable $render): array
    {
        $queries=[];$http=[];
        $query=static function(string $sql) use (&$queries): string {
            foreach(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame){
                if(in_array($frame['class']??'',[
                    'DigiForge\\Security\\PersonalizedPodAccess',
                    OpportunityDesignPanel::class,CommercialResearchPanel::class,
                    'DigiForge\\Portal\\DesignFactoryPanel','DigiForge\\Portal\\ProductPackagePanel',
                    'DigiForge\\Research\\OpportunityDesignRepository','DigiForge\\Research\\CommercialResearchRepository',
                    'DigiForge\\Research\\DesignFactoryRepository','DigiForge\\Research\\GovernedResearchProvider',
                    'DigiForge\\POD\\ProductPackageRepository',
                ],true)){$queries[]=$sql;break;}
            }
            return $sql;
        };
        $deny=static function($pre,$args,$url) use (&$http){$http[]=$url;return new WP_Error('isolated_no_http','External requests prohibited.');};
        add_filter('query',$query);add_filter('pre_http_request',$deny,10,3);ob_start();
        try{$render();$html=ob_get_contents();}
        finally{ob_end_clean();remove_filter('query',$query);remove_filter('pre_http_request',$deny,10);}
        return compact('html','queries','http');
    }
    private function state(): array
    {
        global $wpdb;$out=[];
        foreach([
            'digital'=>[Tables::digital_products(),'id'],
            'settings'=>[Tables::settings(),'setting_key'],
            'jobs'=>[Tables::jobs(),'id'],
            'ai_usage'=>[Tables::ai_usage(),'id'],
            'shop_ai_usage'=>[Tables::shop_ai_usage(),'id'],
            'policies'=>[Tables::shop_ai_policies(),'id'],
            'integrations'=>[Tables::integrations(),'id'],
        ] as $key=>[$table,$order]){
            $wpdb->last_error='';$rows=$wpdb->get_results("SELECT * FROM $table ORDER BY $order",ARRAY_A);
            self::assertSame('',$wpdb->last_error);$out[$key]=$rows;
        }
        $wpdb->last_error='';$out['module_and_drive_options']=$wpdb->get_results($wpdb->prepare(
            "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",
            $wpdb->esc_like('digiforge_').'%'
        ),ARRAY_A);self::assertSame('',$wpdb->last_error);
        return $out;
    }
    public static function nonPodPanels(): array
    {
        $cases=[];foreach(['digital','standard_pod','jewelry','all'] as $shop){
            $cases[$shop.' opportunity']=[OpportunityDesignPanel::class,$shop];
            $cases[$shop.' commercial']=[CommercialResearchPanel::class,$shop];
        }return $cases;
    }
    /** @dataProvider nonPodPanels */
    public function testNonPodPanelReturnsBeforeAnyModuleAdmissionOrStateEffect(string $panel,string $shop): void
    {
        $before=$this->state();$observed=$this->observe(static fn()=>(new $panel())->render($shop));
        self::assertSame('',$observed['html']);self::assertSame([],$observed['http']);
        self::assertSame([],$observed['queries'],'Other shops must not enter POD admission, storage or denial-audit paths.');
        self::assertSame($before,$this->state());
    }
    public static function digitalActors(): array {return ['Digital only'=>[false],'Digital plus POD'=>[true]];}
    /** @dataProvider digitalActors */
    public function testExistingDigitalResearchHasNoAdditionalPodAdmissionStorageAuditJobsOrBudgetEffects(bool $pod): void
    {
        if($pod)ShopAccessFixture::authorizeActor(get_current_user_id(),['personalized_pod'],Capabilities::ALL);
        global $wpdb;$id=get_current_user_id();
        self::assertSame(1,$wpdb->insert(Tables::digital_products(),[
            'name'=>'Synthetic Digital preservation sentinel','category'=>'test','product_id'=>$id,'product_version_id'=>$id,
            'state'=>'PLATFORM_DRAFT','readiness'=>wp_json_encode(DigiForge\DigitalFactory\Lifecycle::readiness()),
            'created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true),
        ]));
        $before=$this->state();$get=$_GET;
        try{$_GET=['df_view'=>'research','df_shop'=>'digital'];$observed=$this->observe(static function(): void {echo (new Portal())->render();});}
        finally{$_GET=$get;}
        self::assertStringContainsString('Manual research run',$observed['html']);
        self::assertStringContainsString('All research candidates',$observed['html']);
        self::assertStringNotContainsString('id="df-commercial-research"',$observed['html']);
        self::assertStringNotContainsString('digiforge_opportunity_design',$observed['html']);
        self::assertSame([],$observed['http']);
        // Core navigation can make its preexisting cross-shop checks. Any SQL under
        // a new module frame, including audit INSERTs, is an additional module effect.
        self::assertSame([],$observed['queries'],'Digital Research must not run the Personalized POD module.');
        self::assertSame($before,$this->state());
        self::assertTrue(Settings::get('stop_all'));self::assertTrue(Settings::safety_locked());self::assertFalse(Settings::get('automation_armed'));
    }
    public function testAuthorizedPodStillRendersNonceProtectedControlsUnderGlobalHold(): void
    {
        ShopAccessFixture::authorizeActor(get_current_user_id(),['personalized_pod'],Capabilities::ALL);
        $get=$_GET;
        try{$_GET=['df_view'=>'research','df_shop'=>'personalized_pod'];$observed=$this->observe(static function(): void {echo (new Portal())->render();});}
        finally{$_GET=$get;}
        self::assertStringContainsString('Manual research run',$observed['html']);
        self::assertStringContainsString('id="df-commercial-research"',$observed['html']);
        self::assertStringContainsString('digiforge_opportunity_design',$observed['html']);
        self::assertStringContainsString('_wpnonce',$observed['html']);self::assertNotEmpty($observed['queries']);self::assertSame([],$observed['http']);
        self::assertTrue(Settings::get('stop_all'));self::assertTrue(Settings::safety_locked());self::assertFalse(Settings::get('automation_armed'));
    }
    public function testPodWithoutSignedAssignmentStillDeniesAndAudits(): void
    {
        $observed=$this->observe(static fn()=>(new OpportunityDesignPanel())->render('personalized_pod'));
        self::assertSame('',$observed['html']);self::assertSame([],$observed['http']);self::assertNotEmpty($observed['queries']);
        $audit=array_filter($observed['queries'],static fn($sql)=>str_contains($sql,Tables::audit_log())&&str_contains($sql,'shop_access_denied'));
        self::assertNotEmpty($audit,'The shop-first no-op must not suppress real unauthorized POD admission audits.');
    }
}
