<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
require_once __DIR__.'/PersonalizedPodScopeAcceptanceTest.php';
use DigiForge\Core\Settings;
use DigiForge\Portal\Portal;
use DigiForge\Security\ShopAccess;
use DigiForge\Security\ShopAccessPolicy;

/** Owner phase-1 scope is local acceptance, never activation or provider certification. */
final class TwoShopPortalAcceptanceTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();DigiForge\Core\Activator::activate();Settings::protectProduction();ShopAccessFixture::reset();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        ShopAccessFixture::authorizeActor(get_current_user_id(),['digital','personalized_pod'],DigiForge\Core\Capabilities::ALL);
    }
    protected function tearDown(): void
    {
        self::assertTrue(Settings::get('stop_all'));self::assertTrue(Settings::safety_locked());self::assertFalse(Settings::get('automation_armed'));parent::tearDown();
    }
    private function render(string $view,string $shop): string
    {
        $old=$_GET;try{$_GET=['df_view'=>$view,'df_shop'=>$shop];return (new Portal())->render();}finally{$_GET=$old;}
    }
    public function testTwoShopOperatorCanInspectScopedPersonalizedWorkflowWithoutGlobalCatalogQueries(): void
    {
        $fixture=new PersonalizedPodScopeAcceptanceTest('testShopPredicatePrecedesWindowAndDoesNotGrantProduction');
        $method=new ReflectionMethod($fixture,'package');$own=$method->invoke($fixture,'personalized_pod');$foreign=$method->invoke($fixture,'standard_pod');
        $queries=[];$capture=static function($sql)use(&$queries){$queries[]=$sql;return $sql;};add_filter('query',$capture);
        try{$html=$this->render('pod_personalized','personalized_pod');}finally{remove_filter('query',$capture);}
        self::assertStringContainsString('Buyer-specific artwork & mockup review',$html);
        self::assertStringContainsString('Phase-1 Personalized POD operations',$html);
        self::assertStringContainsString('#'.$own.'</td>',$html);self::assertStringNotContainsString('#'.$foreign.'</td>',$html);
        self::assertStringNotContainsString('Master 500 v2 ingestion readiness',$html);
        self::assertStringNotContainsString('Master 500 governed source',$html);
        self::assertStringContainsString('Global catalog evidence requires assignments for all four shops.',$html);
        foreach($queries as $sql)self::assertStringNotContainsString('FROM '.DigiForge\Database\Tables::catalog_versions(),$sql);
        self::assertStringNotContainsString('<option value="standard_pod"',$html);self::assertStringNotContainsString('<option value="jewelry"',$html);self::assertStringNotContainsString('<option value="all"',$html);
        $dir=getenv('DF_TWO_SHOP_FIXTURE_DIR');if(is_string($dir)&&$dir!==''){if(!is_dir($dir))mkdir($dir,0770,true);file_put_contents($dir.'/personalized.html',$html);file_put_contents($dir.'/digital.html',$this->render('digital','digital'));}
    }
    public function testDeferredShopsAndGlobalViewsRemainDeniedWithoutChangingFourShopArchitecture(): void
    {
        self::assertSame(['digital','personalized_pod','standard_pod','jewelry'],ShopAccessPolicy::canonicalShops());
        foreach(['standard_pod','jewelry','all'] as $shop){self::assertStringContainsString('An explicit assignment is required',$this->render('pod_personalized',$shop));}
        foreach(['standard_pod','jewelry'] as $shop)self::assertWPError(ShopAccess::authorize($shop,'manage_digiforge'));
        self::assertStringContainsString('requires assignments for all four shops',$this->render('dashboard','digital'));
        self::assertStringContainsString('Digital',$this->render('digital','digital'));
    }
    public function testRevocationRemovesPreviouslyAvailableWorkflowWithoutGrantingDeferredAccess(): void
    {
        self::assertStringContainsString('Phase-1 Personalized POD operations',$this->render('pod_personalized','personalized_pod'));
        ShopAccessFixture::provision([]);
        foreach(['digital','personalized_pod','standard_pod','jewelry'] as $shop)self::assertStringContainsString('An explicit assignment is required',$this->render('pod_personalized',$shop));
    }
}
