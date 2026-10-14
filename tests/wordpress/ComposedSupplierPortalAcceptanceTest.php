<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
require_once __DIR__.'/BusinessOwnershipPortalAcceptanceTest.php';
use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Portal\BusinessOwnershipReadModel;
use DigiForge\Portal\Portal;

/** Composed native supplier views. Every identity and approval here is synthetic local evidence. */
final class ComposedSupplierPortalAcceptanceTest extends WP_UnitTestCase
{
    private int $operator;
    private Closure $http;
    private int $calls=0;
    protected function setUp(): void
    {
        parent::setUp();DigiForge\Core\Activator::activate();Settings::protectProduction();ShopAccessFixture::reset();
        $this->operator=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($this->operator);
        ShopAccessFixture::authorizeActor($this->operator,['digital','personalized_pod','standard_pod','jewelry'],['manage_digiforge','manage_digiforge_pod','manage_digiforge_orders']);
        wp_create_nonce('composed-supplier-fixture');
        $this->http=function(){++$this->calls;return new WP_Error('offline_composed_supplier','No actual HTTP.');};add_filter('pre_http_request',$this->http);
    }
    protected function tearDown(): void
    {
        remove_filter('pre_http_request',$this->http);self::assertSame(0,$this->calls);self::assertTrue(Settings::safety_locked());self::assertTrue(Settings::get('stop_all'));self::assertFalse(Settings::get('automation_armed'));parent::tearDown();
    }
    private function ownership(string $shop): array
    {
        $fixture=new BusinessOwnershipPortalAcceptanceTest('testActualOwnershipProviderRowsScopeBeforeWindowAndNeverAuthorizeProduction');
        return (new ReflectionMethod($fixture,'fixture'))->invoke($fixture,$shop,'composed-'.$shop.'-'.wp_generate_uuid4());
    }
    private function render(string $view,string $shop): string
    {
        $old=$_GET;try{$_GET=['df_view'=>$view,'df_shop'=>$shop];return (new Portal())->render();}finally{$_GET=$old;}
    }
    public function testFourShopSupplierRowsAndProviderWindowsRemainSelectedBeforeLimits(): void
    {
        $records=[];foreach(['digital','personalized_pod','standard_pod','jewelry'] as $shop){$records[$shop]=$this->ownership($shop);}
        global $wpdb;$plans=[];
        foreach(array_keys($records) as $shop){
            $order=(new DigiForge\Orders\Repository())->createOrder(['channel'=>'etsy','environment'=>'sandbox','shop_reference'=>$shop]);self::assertNotWPError($order);$hash=hash('sha256',wp_generate_uuid4());$now=current_time('mysql',true);
            self::assertSame(1,$wpdb->insert(Tables::fulfillment_plans(),['order_id'=>$order['id'],'plan_version'=>'composed-'.$shop,'provider'=>'printify','canonical_payload'=>'{}','payload_hash'=>$hash,'readiness'=>'{}','readiness_hash'=>$hash,'state'=>'DRAFT','environment'=>'sandbox','created_at'=>$now,'updated_at'=>$now]));$plans[$shop]=(int)$wpdb->insert_id;
        }
        foreach($records as $shop=>$own){
            $snapshot=(new BusinessOwnershipReadModel())->snapshot($shop,1);self::assertSame('AVAILABLE',$snapshot['query_state']);self::assertSame([$own['ownership']],array_map('intval',array_column($snapshot['items'],'ownership_id')));
            $state=null;$rows=(new DigiForge\Portal\OperationalDepthReadModel())->fulfillmentProviders(1,$state,$shop);self::assertSame('AVAILABLE',$state);self::assertSame([$plans[$shop]],array_map('intval',array_column($rows,'id')));
            $html=$this->render('businesses',$shop);self::assertStringContainsString($snapshot['items'][0]['business_name'],$html);
            foreach($records as $foreignShop=>$foreign){if($foreignShop!==$shop){self::assertStringNotContainsString('['.$wpdb->get_var($wpdb->prepare('SELECT business_key FROM '.Tables::businesses().' WHERE id=%d',$foreign['business'])).']',$html);}}
            $fulfillment=$this->render('fulfillment',$shop);self::assertStringContainsString('composed-'.$shop,$fulfillment);
            $dir=getenv('DF_COMPOSED_PORTAL_FIXTURE_DIR');if(is_string($dir)&&$dir!==''){file_put_contents($dir.'/supplier-'.$shop.'.html',$html);file_put_contents($dir.'/fulfillment-'.$shop.'.html',$fulfillment);}
            foreach(array_keys($records) as $foreignShop){if($foreignShop!==$shop)self::assertStringNotContainsString('composed-'.$foreignShop,$fulfillment);}
            self::assertStringContainsString('A prepared or approved plan is not production authorization',$fulfillment);
        }
    }
    public function testUnassignedForeignAndRevokedAssignmentsCannotReadSupplierOwnership(): void
    {
        $this->ownership('personalized_pod');
        $limited=self::factory()->user->create(['role'=>'administrator']);ShopAccessFixture::authorizeActor($limited,['digital'],['manage_digiforge','manage_digiforge_pod']);wp_set_current_user($limited);
        self::assertSame('ACCESS_DENIED',(new BusinessOwnershipReadModel())->snapshot('personalized_pod')['query_state']);
        self::assertSame('ACCESS_DENIED',(new BusinessOwnershipReadModel())->snapshot('all')['query_state']);
        self::assertStringNotContainsString('Stored business and provider ownership',$this->render('businesses','personalized_pod'));
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        self::assertSame('ACCESS_DENIED',(new BusinessOwnershipReadModel())->snapshot('digital')['query_state']);
        wp_set_current_user($this->operator);ShopAccessFixture::provision([]);
        self::assertSame('ACCESS_DENIED',(new BusinessOwnershipReadModel())->snapshot('personalized_pod')['query_state']);
    }
    public function testAmbiguousAndUnknownOwnershipNeverDisplaysApprovedAncestry(): void
    {
        global $wpdb;$own=$this->ownership('personalized_pod');$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_business_mappings().' WHERE id=%d',$own['ownership']),ARRAY_A);unset($row['id']);$row['product_version_id']+=1000000;self::assertSame(1,$wpdb->insert(Tables::pod_business_mappings(),$row));
        $ambiguous=(new BusinessOwnershipReadModel())->snapshot('personalized_pod');$matches=array_values(array_filter($ambiguous['items'],static fn($r)=>(int)$r['ownership_id']===$own['ownership']));self::assertCount(1,$matches);self::assertSame('AMBIGUOUS_OWNERSHIP',$matches[0]['evidence_state']);
    }
    public function testUnknownAncestryCannotBecomeApprovedThroughGlobalSupplierView(): void
    {
        global $wpdb;$unknown=$this->ownership('jewelry');self::assertSame(1,$wpdb->delete(Tables::research_candidates(),['id'=>$unknown['candidate']]));
        $all=(new BusinessOwnershipReadModel())->snapshot('all');$matches=array_values(array_filter($all['items'],static fn($r)=>(int)$r['ownership_id']===$unknown['ownership']));self::assertCount(1,$matches);self::assertSame('UNVERIFIED_SHOP_ANCESTRY',$matches[0]['evidence_state']);
        self::assertSame([],(new BusinessOwnershipReadModel())->snapshot('jewelry')['items']);
    }
    public function testGlobalTotalsAreSeparatelyLabelledAndRequireAllShopAssignments(): void
    {
        $html=$this->render('dashboard','digital');self::assertStringContainsString('Dashboard counts remain authoritative global counts unless explicitly labeled shop-scoped.',$html);
        $queue=$this->render('dashboard','digital');self::assertStringContainsString('Global evidence across all four shops. Read-only visibility requires explicit assignments for every shop.',$queue);
        $limited=self::factory()->user->create(['role'=>'administrator']);ShopAccessFixture::authorizeActor($limited,['digital'],['manage_digiforge']);wp_set_current_user($limited);
        self::assertStringNotContainsString('Global queue health',$this->render('attention','digital'));
        self::assertStringNotContainsString('Current shop scope',$this->render('dashboard','digital'));
    }
    public function testSupplierReadFailureIsUnavailableAndNeverAnEmptyApprovedRegistry(): void
    {
        global $wpdb;$this->ownership('digital');$bad=static fn($sql)=>str_contains($sql,'FROM '.Tables::pod_business_mappings().' own')?'SELECT * FROM composed_missing_ownership_evidence':$sql;
        $old=$wpdb->suppress_errors(true);add_filter('query',$bad);try{$html=$this->render('businesses','digital');self::assertStringContainsString('Business/provider ownership evidence unavailable',$html);self::assertStringNotContainsString('No linked ownership evidence in this selected shop window.',$html);}finally{remove_filter('query',$bad);$wpdb->suppress_errors($old);}
    }
}
