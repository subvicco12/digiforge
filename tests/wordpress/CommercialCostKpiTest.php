<?php
declare(strict_types=1);
final class CommercialCostKpiTest extends WP_UnitTestCase
{
    protected function setUp():void{parent::setUp();DigiForge\Core\Activator::activate();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));}
    private function fixture(bool $settle=true,string $amount='0.120000'):array
    {
        global $wpdb;$t=DigiForge\Database\Tables::class;$now=current_time('mysql',true);
        $gov=new DigiForge\AI\ShopAiGovernanceRepository();self::assertNotWPError($gov->savePolicy(['shop_key'=>'digital','currency'=>'USD','stages'=>['develop'=>['limit'=>20]]]));
        $usage=$gov->reserveGeneration('digital','develop',wp_generate_uuid4());self::assertNotWPError($usage);
        $wpdb->insert($t::opportunities(),['title'=>'Offline qualified','state'=>'QUALIFIED','created_at'=>$now,'updated_at'=>$now]);$opportunity=(int)$wpdb->insert_id;
        $wpdb->insert($t::research_candidates(),['title'=>'Offline','canonical_title'=>'offline','fingerprint'=>hash('sha256',wp_generate_uuid4()),'summary'=>'Offline evidence','score_version'=>'v1','score_inputs'=>'{"shop_key":"digital"}','review_status'=>'APPROVED','opportunity_id'=>$opportunity,'created_at'=>$now,'updated_at'=>$now]);$candidate=(int)$wpdb->insert_id;
        $wpdb->insert($t::research_reviews(),['candidate_id'=>$candidate,'decision'=>'APPROVED','notes'=>'Offline approval fixture','reviewed_by'=>get_current_user_id(),'reviewed_at'=>$now]);
        $wpdb->insert($t::product_families(),['opportunity_id'=>$opportunity,'name'=>'Offline','created_at'=>$now,'updated_at'=>$now]);$family=(int)$wpdb->insert_id;
        $wpdb->insert($t::products(),['product_family_id'=>$family,'name'=>'Offline','state'=>'READY','created_at'=>$now,'updated_at'=>$now]);$product=(int)$wpdb->insert_id;
        $wpdb->insert($t::product_versions(),['product_id'=>$product,'version_label'=>'1','state'=>'APPROVED','created_at'=>$now,'updated_at'=>$now]);$version=(int)$wpdb->insert_id;
        $wpdb->insert($t::listings(),['product_version_id'=>$version,'shop_reference'=>'digital','channel'=>'etsy','environment'=>'sandbox','title'=>'Offline approved','state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);$listing=(int)$wpdb->insert_id;
        $event='offline-'.wp_generate_uuid4();$wpdb->insert($t::orders(),['channel'=>'etsy','environment'=>'sandbox','external_order_reference'=>'offline','shop_reference'=>'digital','currency'=>'USD','state'=>'APPROVED','idempotency_key'=>'etsy_webhook:'.$event,'created_at'=>$now,'updated_at'=>$now]);$order=(int)$wpdb->insert_id;
        $wpdb->insert($t::order_line_items(),['order_id'=>$order,'listing_id'=>$listing,'product_version_id'=>$version,'quantity'=>1,'currency'=>'USD','environment'=>'sandbox','validation_status'=>'VALIDATED','created_at'=>$now,'updated_at'=>$now]);
        $wpdb->insert($t::webhook_evidence(),['provider'=>'etsy','event_id'=>$event,'event_type'=>'ORDER.PAID','shop_reference'=>'digital','verification_status'=>'VERIFIED','processing_status'=>'PROCESSED','body_sha256'=>hash('sha256','offline-paid-fixture'),'result_hash'=>hash('sha256','offline-result'),'received_at'=>$now,'processed_at'=>$now]);
        self::assertNotWPError((new DigiForge\AI\CostAttributionRepository())->review((int)$usage['id'],'digital',['product_id'=>$product,'order_id'=>$order],'CONFIRM_COST_ATTRIBUTION'));
        $provider=new DigiForge\AI\ProviderEvidenceRepository();$response=['response_id'=>'resp_'.str_replace('-','',wp_generate_uuid4()),'model'=>'offline-model','status'=>'completed','usage'=>['input_tokens'=>1,'output_tokens'=>1,'total_tokens'=>2]];self::assertNotWPError($provider->recordOutcome((int)$usage['id'],'digital',$response));
        if($settle){$document=(string)wp_json_encode(['provider'=>'openai','invoice_id'=>'offline-'.wp_generate_uuid4(),'lines'=>[['line_id'=>'1','response_id'=>$response['response_id'],'model'=>'offline-model','currency'=>'USD','amount'=>$amount]]]);self::assertNotWPError((new DigiForge\Portal\AiEvidenceWorkflow())->submit(['operation'=>'charge','shop_key'=>'digital','usage_id'=>$usage['id'],'decision'=>'CONFIRM_PROVIDER_STATEMENT_CHARGE','document'=>$document]));}
        return compact('usage','product','opportunity','listing','order','candidate');
    }
    private function snapshot(string $shop='digital'):array{return (new DigiForge\AI\CommercialCostKpiReadModel())->snapshot($shop,gmdate('Y-m-01 00:00:00'),gmdate('Y-m-01 00:00:00',strtotime('first day of next month UTC')),'USD');}
    public function testReviewedProviderChargeJoinsExactStoredCommercialLineage():void
    {
        self::assertTrue(class_exists(DigiForge\AI\CommercialCostKpiReadModel::class),'Attributable commercial projection missing.');$f=$this->fixture();$r=$this->snapshot();self::assertSame('AVAILABLE',$r['query_state']);
        foreach(['opportunity','qualified_opportunity','developed_product','approved_listing','sale']as $metric){self::assertSame('AVAILABLE',$r['metrics'][$metric]['state']);self::assertSame('0.120000',$r['metrics'][$metric]['cost']);self::assertSame(1,$r['metrics'][$metric]['denominator']);self::assertSame('0.120000',$r['metrics'][$metric]['cost_per_outcome']);}
        self::assertSame([$f['product']],$r['metrics']['developed_product']['entity_ids']);self::assertSame('FINANCE_DEFERRED',$r['metrics']['revenue_ratio']['state']);self::assertFalse($r['external_execution_authorized']);
    }
    public function testMissingChargesAndOtherShopsAreNotVerifiedZero():void
    {
        self::assertTrue(class_exists(DigiForge\AI\CommercialCostKpiReadModel::class),'Attributable commercial projection missing.');$this->fixture(false);$r=$this->snapshot();self::assertSame('PENDING_COST_EVIDENCE',$r['metrics']['developed_product']['state']);self::assertNull($r['metrics']['developed_product']['cost_per_outcome']);$other=$this->snapshot('jewelry');self::assertSame('NO_ATTRIBUTABLE_OUTCOMES',$other['metrics']['sale']['state']);self::assertNull($other['metrics']['sale']['cost_per_outcome']);
    }
    public function testReviewedZeroChargeIsAvailableAndPeriodEndIsExclusive():void
    {
        $f=$this->fixture(true,'0.000000');$r=$this->snapshot();self::assertSame('AVAILABLE',$r['metrics']['developed_product']['state']);self::assertSame('0.000000',$r['metrics']['developed_product']['cost_per_outcome']);self::assertSame(1,$r['metrics']['developed_product']['denominator']);
        global $wpdb;$end=gmdate('Y-m-01 00:00:00',strtotime('first day of next month UTC'));$wpdb->update(DigiForge\Database\Tables::shop_ai_usage(),['occurred_at'=>$end],['id'=>$f['usage']['id']]);self::assertSame('NO_ATTRIBUTABLE_OUTCOMES',$this->snapshot()['metrics']['developed_product']['state']);
    }
    public function testQualifiedDenominatorRequiresActualHumanReviewReceipt():void
    {
        $f=$this->fixture();global $wpdb;$wpdb->delete(DigiForge\Database\Tables::research_reviews(),['candidate_id'=>$f['candidate']]);$r=$this->snapshot();self::assertSame('MISSING_LINEAGE',$r['metrics']['qualified_opportunity']['state']);self::assertNull($r['metrics']['qualified_opportunity']['cost_per_outcome']);self::assertSame('AVAILABLE',$r['metrics']['opportunity']['state']);
    }
    public function testOpportunityReviewCannotAttachAnUnrelatedStoredOrder():void
    {
        $f=$this->fixture();global $wpdb;$t=DigiForge\Database\Tables::class;$now=current_time('mysql',true);$wpdb->insert($t::opportunities(),['title'=>'Other scoped opportunity','state'=>'NEW','created_at'=>$now,'updated_at'=>$now]);$other=(int)$wpdb->insert_id;$wpdb->insert($t::research_candidates(),['title'=>'Other','canonical_title'=>'other','fingerprint'=>hash('sha256',wp_generate_uuid4()),'summary'=>'Offline','score_version'=>'v1','score_inputs'=>'{"shop_key":"digital"}','review_status'=>'APPROVED','opportunity_id'=>$other,'created_at'=>$now,'updated_at'=>$now]);$usage=(new DigiForge\AI\ShopAiGovernanceRepository())->reserveGeneration('digital','develop',wp_generate_uuid4());self::assertNotWPError($usage);self::assertWPError((new DigiForge\AI\CostAttributionRepository())->review((int)$usage['id'],'digital',['opportunity_id'=>$other,'order_id'=>$f['order']],'CONFIRM_COST_ATTRIBUTION'));
    }
    public function testKnownZeroCostTemplateCapacityDoesNotInvalidateProviderCohort():void
    {
        $this->fixture();global $wpdb;$wpdb->insert(DigiForge\Database\Tables::shop_ai_usage(),['shop_key'=>'digital','workflow'=>'production_template','stage'=>'template_prepare','model_key'=>hash('sha256','offline-template'),'run_id'=>'offline-cycle','quantity'=>1,'product_id'=>0,'order_id'=>0,'estimated_cost'=>0,'actual_cost'=>0,'currency'=>'USD','occurred_at'=>current_time('mysql',true),'idempotency_key'=>'template-'.hash('sha256','offline-capacity')]);self::assertSame('AVAILABLE',$this->snapshot()['metrics']['developed_product']['state']);
        $wpdb->update(DigiForge\Database\Tables::shop_ai_usage(),['actual_cost'=>'0.010000'],['id'=>$wpdb->insert_id]);self::assertSame('UNATTRIBUTABLE_USAGE',$this->snapshot()['metrics']['developed_product']['state']);
    }
    public function testPeriodCurrencyAndLineageDriftFailClosed():void
    {
        self::assertTrue(class_exists(DigiForge\AI\CommercialCostKpiReadModel::class),'Attributable commercial projection missing.');$f=$this->fixture();$model=new DigiForge\AI\CommercialCostKpiReadModel();self::assertSame('NO_ATTRIBUTABLE_OUTCOMES',$model->snapshot('digital','2020-01-01 00:00:00','2020-02-01 00:00:00','USD')['metrics']['sale']['state']);self::assertSame('CURRENCY_CONFLICT',$model->snapshot('digital',gmdate('Y-m-01 00:00:00'),'2100-01-01 00:00:00','INR')['metrics']['sale']['state']);global $wpdb;$wpdb->update(DigiForge\Database\Tables::research_candidates(),['score_inputs'=>'{"shop_key":"jewelry"}'],['id'=>$f['candidate']]);self::assertSame('MISSING_LINEAGE',$this->snapshot()['metrics']['developed_product']['state']);
    }
    public function testDatabaseFailureIsUnavailableNotAnEmptyCohort():void
    {
        self::assertTrue(class_exists(DigiForge\AI\CommercialCostKpiReadModel::class),'Attributable commercial projection missing.');$filter=static fn(string $sql):string=>str_contains($sql,'FROM '.DigiForge\Database\Tables::shop_ai_usage())?str_replace(DigiForge\Database\Tables::shop_ai_usage(),'missing_kpi_fixture',$sql):$sql;add_filter('query',$filter);global $wpdb;$wpdb->suppress_errors(true);try{$r=$this->snapshot();}finally{remove_filter('query',$filter);$wpdb->suppress_errors(false);}self::assertSame('UNAVAILABLE',$r['query_state']);self::assertNull($r['metrics']['sale']['denominator']);
    }
    public function testPortalShowsReviewedCohortWithoutFinanceRatiosOrExternalActions():void
    {
        $this->fixture();$id=self::factory()->user->create(['role'=>'subscriber']);$user=new WP_User($id);$user->add_cap('manage_digiforge');$user->add_cap('manage_digiforge_ai');wp_set_current_user($id);$_GET['df_view']='ai_budget';$_GET['df_shop']='digital';try{$html=(new DigiForge\Portal\Portal())->render();}finally{unset($_GET['df_view'],$_GET['df_shop']);}
        self::assertStringContainsString('Reviewed commercial AI cost',$html);self::assertStringContainsString('0.120000',$html);self::assertStringContainsString('FINANCE_DEFERRED',$html);self::assertStringContainsString('Distinct verified paid orders',$html);
        $directory=getenv('DF_PORTAL_FIXTURE_DIR');if(is_string($directory)&&$directory!=='')self::assertSame(strlen($html),file_put_contents($directory.'/portal-ai_budget--digital.html',$html));
    }
    public function testCommercialRestEvidenceRequiresAuthenticationAndExactShop():void
    {
        (new DigiForge\REST\AiEvidenceController())->register();do_action('rest_api_init');
        wp_set_current_user(0);$request=new WP_REST_Request('GET','/digiforge/v1/ai/commercial-kpis');self::assertSame(401,rest_get_server()->dispatch($request)->get_status());
        wp_set_current_user(self::factory()->user->create(['role'=>'subscriber']));self::assertSame(403,rest_get_server()->dispatch($request)->get_status());
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));self::assertSame(400,rest_get_server()->dispatch($request)->get_status());
        $this->fixture();$request->set_query_params(['shop_key'=>'digital','start_utc'=>gmdate('Y-m-01 00:00:00'),'end_exclusive_utc'=>gmdate('Y-m-01 00:00:00',strtotime('first day of next month UTC')),'currency'=>'USD']);$r=rest_get_server()->dispatch($request);self::assertSame(200,$r->get_status());self::assertSame('0.120000',$r->get_data()['metrics']['sale']['cost_per_outcome']);self::assertFalse($r->get_data()['external_execution_authorized']);
    }
    public function testResearchCostCanUseReviewedOpportunityBeforeAnyProductExists():void
    {
        $f=$this->fixture();global $wpdb;$wpdb->update(DigiForge\Database\Tables::shop_ai_usage(),['occurred_at'=>'2020-01-01 00:00:00'],['id'=>$f['usage']['id']]);$gov=new DigiForge\AI\ShopAiGovernanceRepository();self::assertNotWPError($gov->savePolicy(['shop_key'=>'digital','currency'=>'USD','stages'=>['research'=>['limit'=>20],'develop'=>['limit'=>20]]]));$usage=$gov->reserveGeneration('digital','research',wp_generate_uuid4());self::assertNotWPError($usage);
        $attribution=(new DigiForge\AI\CostAttributionRepository())->review((int)$usage['id'],'digital',['opportunity_id'=>$f['opportunity']],'CONFIRM_COST_ATTRIBUTION');self::assertNotWPError($attribution);self::assertSame(0,$attribution['product_id']);self::assertSame($f['opportunity'],$attribution['opportunity_id']);
        $response=['response_id'=>'resp_'.str_replace('-','',wp_generate_uuid4()),'model'=>'offline-model','status'=>'completed','usage'=>['input_tokens'=>1,'output_tokens'=>1,'total_tokens'=>2]];self::assertNotWPError((new DigiForge\AI\ProviderEvidenceRepository())->recordOutcome((int)$usage['id'],'digital',$response));$document=(string)wp_json_encode(['provider'=>'openai','invoice_id'=>'offline-'.wp_generate_uuid4(),'lines'=>[['line_id'=>'1','response_id'=>$response['response_id'],'model'=>'offline-model','currency'=>'USD','amount'=>'0.060000']]]);self::assertNotWPError((new DigiForge\Portal\AiEvidenceWorkflow())->submit(['operation'=>'charge','shop_key'=>'digital','usage_id'=>$usage['id'],'decision'=>'CONFIRM_PROVIDER_STATEMENT_CHARGE','document'=>$document]));
        $r=$this->snapshot();self::assertSame('0.060000',$r['metrics']['opportunity']['cost_per_outcome']);self::assertSame('MISSING_LINEAGE',$r['metrics']['developed_product']['state']);self::assertNull($r['metrics']['sale']['cost_per_outcome']);self::assertWPError((new DigiForge\AI\CostAttributionRepository())->review((int)$usage['id'],'jewelry',['opportunity_id'=>$f['opportunity']],'CONFIRM_COST_ATTRIBUTION'));
    }
 public function testZeroAdministrativeCurrencyCannotPoisonReviewedCommercialCohort():void {
  $this->fixture();global $wpdb;$wpdb->insert(DigiForge\Database\Tables::shop_ai_usage(),['shop_key'=>'digital','workflow'=>'production_template','stage'=>'template_prepare','model_key'=>hash('sha256','offline-inr-template'),'product_id'=>0,'order_id'=>0,'quantity'=>1,'estimated_cost'=>0,'actual_cost'=>0,'currency'=>'INR','occurred_at'=>current_time('mysql',true),'idempotency_key'=>'template-'.hash('sha256','offline-inr-template')]);
  $r=(new DigiForge\AI\CostKpiReadModel())->snapshot('digital');self::assertSame('UNAVAILABLE',$r['query_state']);self::assertSame('CURRENCY_CONFLICT',$r['currency_state']);self::assertSame('USD',$r['commercial_kpis']['currency']);self::assertSame('AVAILABLE',$r['commercial_kpis']['metrics']['sale']['state']);self::assertSame('0.120000',$r['business_kpis']['cost_per_sale']);
 }

}
