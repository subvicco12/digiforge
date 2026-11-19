<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
require_once __DIR__.'/PersonalizedPodScopeAcceptanceTest.php';
require_once __DIR__.'/Support/ReviewedListingFixture.php';
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
        wp_create_nonce('two-shop-read-only');
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
    public function testTwoShopListingReviewAndUploadEvidenceRemainScopedWithoutGlobalConfiguration(): void
    {
        $fixture=ReviewedListingFixture::create($this);global $wpdb;$table=$wpdb->prefix.'digiforge_etsy_operations';
        self::assertSame(1,$wpdb->update($table,['shop_reference'=>'digital'],['id'=>$fixture['upload']]));
        $foreign=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE id=%d',$fixture['upload']),ARRAY_A);unset($foreign['id']);$foreign['shop_reference']='jewelry';$foreign['idempotency_key']=wp_generate_uuid4();$foreign['external_asset_reference']='989898';self::assertSame(1,$wpdb->insert($table,$foreign));
        $html=$this->render('listings','digital');
        self::assertStringContainsString('Listing / publish decisions (Gate 3)',$html);
        self::assertStringContainsString('#'.$fixture['upload'].'</td><td>digital</td><td>5678</td><td>9012',$html);
        self::assertStringNotContainsString('989898',$html);self::assertStringNotContainsString('Signing secret configured',$html);
        self::assertStringContainsString('Global webhook configuration requires assignments for all four shops.',$html);
        self::assertStringContainsString('NO UPLOAD / NO PUBLISH',$html);
        self::assertStringContainsString('Listing / publish decisions (Gate 3)',$this->render('listings','personalized_pod'));
        foreach(['jewelry','standard_pod','all'] as $shop)self::assertStringContainsString('An explicit assignment is required',$this->render('listings',$shop));
        $dir=getenv('DF_TWO_SHOP_FIXTURE_DIR');if(is_string($dir)&&$dir!=='')file_put_contents($dir.'/listings.html',$html);
        ShopAccessFixture::provision([]);self::assertStringContainsString('An explicit assignment is required',$this->render('listings','digital'));
    }
    public function testTwoShopListingReadFailureIsUnavailableRatherThanAnEmptyQueue(): void
    {
        global $wpdb;$prior=$wpdb->suppress_errors(true);$bad=static fn($sql)=>str_contains($sql,'FROM '.DigiForge\Database\Tables::listing_readiness_reviews().' r INNER JOIN')?'SELECT * FROM missing_two_shop_listing_reviews':$sql;
        add_filter('query',$bad);try{$html=$this->render('listings','digital');self::assertStringContainsString('Gate 3 review evidence is unavailable. No decision is permitted.',$html);self::assertStringNotContainsString('No listing Gate 3 reviews currently require a decision.',$html);}finally{remove_filter('query',$bad);$wpdb->suppress_errors($prior);}
    }
    public function testTwoShopReconciliationIsReadOnlyScopedAndRevocationAware(): void
    {
        $fixture=ReviewedListingFixture::create($this);global $wpdb;$table=$wpdb->prefix.'digiforge_etsy_operations';
        self::assertSame(1,$wpdb->update($table,['shop_reference'=>'digital','state'=>'UNKNOWN'],['id'=>$fixture['create']]));
        $foreign=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE id=%d',$fixture['create']),ARRAY_A);unset($foreign['id']);$foreign['shop_reference']='jewelry';$foreign['idempotency_key']=wp_generate_uuid4();$foreign['external_reference']='FOREIGN_RECONCILIATION';self::assertSame(1,$wpdb->insert($table,$foreign));
        wp_create_nonce('two-shop-read-only');
        $queries=[];$capture=static function($sql)use(&$queries){$queries[]=$sql;return $sql;};add_filter('query',$capture);
        try{$html=$this->render('audit','digital');}finally{remove_filter('query',$capture);}
        self::assertStringContainsString('Selected-shop reconciliation (read only)',$html);self::assertStringContainsString('#'.$fixture['create'].'</td>',$html);self::assertStringNotContainsString('FOREIGN_RECONCILIATION',$html);
        self::assertStringContainsString('RECONCILE_BEFORE_ANY_RETRY',$html);self::assertStringNotContainsString('<form method="post"',$html);self::assertStringNotContainsString('Finance/GST exceptions',$html);
        foreach($queries as $sql){if(str_starts_with($sql,'INSERT INTO `'.DigiForge\Database\Tables::audit_log().'`')&&str_contains($sql,"'shop_access_denied'"))continue;self::assertDoesNotMatchRegularExpression('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP)\b/i',$sql);self::assertStringNotContainsString('digiforge_finance_',$sql);}
        foreach(['standard_pod','jewelry','all'] as $shop)self::assertStringContainsString('An explicit assignment is required',$this->render('audit',$shop));
        $dir=getenv('DF_TWO_SHOP_FIXTURE_DIR');if(is_string($dir)&&$dir!=='')file_put_contents($dir.'/reconciliation.html',$html);
        $prior=$wpdb->suppress_errors(true);$bad=static fn($sql)=>str_contains($sql,'FROM '.$table.' WHERE state IN')?'SELECT * FROM missing_two_shop_reconciliation':$sql;add_filter('query',$bad);
        try{self::assertStringContainsString('Etsy reconciliation evidence unavailable',$this->render('audit','digital'));}finally{remove_filter('query',$bad);$wpdb->suppress_errors($prior);}
        $bad=static fn($sql)=>str_contains($sql,'FROM '.DigiForge\Database\Tables::pod_execution_unknowns().' WHERE')?'SELECT * FROM missing_two_shop_printify_reconciliation':$sql;add_filter('query',$bad);$prior=$wpdb->suppress_errors(true);
        try{self::assertStringContainsString('Printify reconciliation evidence unavailable',$this->render('audit','digital'));}finally{remove_filter('query',$bad);$wpdb->suppress_errors($prior);}
        ShopAccessFixture::provision([]);self::assertStringContainsString('An explicit assignment is required',$this->render('audit','digital'));
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
