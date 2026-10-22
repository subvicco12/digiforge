<?php
declare(strict_types=1);

final class GovernedGenerationStopAllAcceptanceTest extends WP_UnitTestCase
{
    private string $shop;
    private int $calls=0;
    private $http;

    protected function setUp(): void
    {
        parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        DigiForge\Core\Settings::set('ai',true);DigiForge\Core\Settings::set('research',true);DigiForge\Core\Settings::set('product_development',true);
        $this->shop='stop-all-fixture-'.wp_rand();
        self::assertNotWPError((new DigiForge\AI\ShopAiGovernanceRepository())->savePolicy(['shop_key'=>$this->shop,'currency'=>'USD','stages'=>['qa'=>['limit'=>10],'develop'=>['limit'=>10],'research'=>['limit'=>10]]]));
        global $wpdb;$tables=DigiForge\Database\Tables::class;$now=current_time('mysql',true);
        $wpdb->update($tables::integrations(),['enabled'=>0],['provider'=>'ai']);
        self::assertSame(1,$wpdb->insert($tables::integrations(),['provider'=>'ai','environment'=>'production','connection_key'=>'stop-fixture-'.wp_generate_uuid4(),'display_name'=>'OFFLINE SAFETY FIXTURE','status'=>'CONFIGURED','enabled'=>1,'config'=>'{}','created_at'=>$now,'updated_at'=>$now]));
        $id=(int)$wpdb->insert_id;
        self::assertSame(1,$wpdb->insert($tables::integration_secrets(),['integration_id'=>$id,'secret_name'=>'api_key','ciphertext'=>DigiForge\Integrations\CredentialVault::encrypt('fixture',DigiForge\Integrations\Repository::secretContext($id,'api_key')),'fingerprint'=>'fixture','created_at'=>$now,'updated_at'=>$now]));
        $this->http=function(){
            $this->calls++;
            return ['headers'=>[],'response'=>['code'=>200],'body'=>wp_json_encode(['id'=>'resp_'.str_replace('-','',wp_generate_uuid4()),'status'=>'completed','model'=>'fixture-model','output_text'=>'{"fixture":true}','usage'=>['input_tokens'=>1,'output_tokens'=>1,'total_tokens'=>2]]),'cookies'=>[]];
        };
        add_filter('pre_http_request',$this->http);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request',$this->http);parent::tearDown();
    }

    public function testActualSemanticQaWithConfiguredQuotaAndConnectorCannotBypassStopAll(): void
    {
        $result=(new DigiForge\ProductFactory\SemanticQa())->inspect([],[],$this->shop,'blocked-semantic-'.wp_generate_uuid4());
        self::assertWPError($result);
        self::assertSame(0,$this->calls,'Configured QA quota and enabled connector cannot override STOP ALL.');
        $this->assertNoReservation();
    }

    public function testAllPublicGenerationFacadesDenyBeforeReservationAndProviderRequest(): void
    {
        $generation=new DigiForge\AI\GovernedGeneration();
        foreach([
            fn()=>$generation->research($this->shop,'Fixture research','blocked-research'),
            fn()=>$generation->develop($this->shop,'develop','Fixture development','blocked-develop'),
            fn()=>$generation->startBackgroundDevelop($this->shop,'develop','Fixture background','blocked-background'),
        ] as $request){self::assertWPError($request());}
        self::assertSame(0,$this->calls);$this->assertNoReservation();
    }

    private function assertNoReservation(): void
    {
        global $wpdb;
        self::assertSame('0',(string)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DigiForge\Database\Tables::shop_ai_usage().' WHERE shop_key=%s',$this->shop)));
        self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertFalse(DigiForge\Core\Settings::get('automation_armed',false));
    }
}
