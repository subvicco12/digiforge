<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyResearchAccessFixture.php';
use DigiForge\POD\PrintifyResearchBatch;
use DigiForge\POD\PrintifyResearchGateway;
use DigiForge\POD\PrintifyResearchReader;
use DigiForge\POD\ProductIntelligence;
use DigiForge\POD\ProductIntelligenceRepository;

/** Synthetic adapter fixture. Never authorizes or contacts a provider. */
final class OfflinePrintifyResearchReader implements PrintifyResearchReader
{
    public int $calls=0;public int $recoveries=0;public array $receipts=[];public bool $interrupt=false;public bool $rateLimit=false;public string $shop='personalized_pod';
    public function describe(string $grant): array|WP_Error {return ['shop_key'=>$this->shop,'account_id'=>get_current_user_id(),'integration_id'=>7,'max_requests'=>2,'requests'=>[['kind'=>'providers','blueprint_id'=>5],['kind'=>'providers','blueprint_id'=>6]]];}
    public function collect(string $grant,int $index,string $key): array|WP_Error
    {
        $this->calls++;if($this->rateLimit)return new WP_Error('catalog_read_account_rate_limit','Synthetic rate limit.',['status'=>429]);
        $blueprint=$index===0?5:6;$request=hash('sha256',$key);
        $receipt=['request'=>['kind'=>'providers','blueprint_id'=>$blueprint],'endpoint_path'=>'/v1/catalog/blueprints/'.$blueprint.'/print_providers.json','observed_at'=>'2026-11-03T14:00:00Z','response_sha256'=>hash('sha256','raw-'.$blueprint),'sha256'=>hash('sha256','receipt-'.$blueprint),'grant_sha256'=>$grant,'request_sha256'=>$request,'integration_id'=>7,'account_id'=>get_current_user_id(),'environment'=>'test','scope'=>['product_program'=>'PERSONALIZED_POD'],'data'=>[['id'=>50+$blueprint,'title'=>'Synthetic provider '.$blueprint,'location'=>['country'=>'US']]]];
        $this->receipts[$request]=$receipt;if($this->interrupt){$this->interrupt=false;return new WP_Error('synthetic_interruption','Receipt persisted before interruption.');}return $receipt;
    }
    public function evidence(string $grant,string $request): array|WP_Error {$this->recoveries++;return $this->receipts[$request]??new WP_Error('catalog_read_evidence_unavailable','No receipt; do not repeat consumed attempt.');}
}

final class PrintifyResearchWordPressTest extends WP_UnitTestCase
{
    private string $snapshot;
    public function setUp(): void
    {
        parent::setUp();PrintifyResearchAccessFixture::reset();$id=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($id);
        foreach(['manage_digiforge','manage_digiforge_pod','manage_digiforge_research','manage_digiforge_connections'] as $cap)wp_get_current_user()->add_cap($cap);
        PrintifyResearchAccessFixture::assign($id);
        $rows=[['V2 ID'=>'DG2-001','Source DG ID'=>'DG-001','Physical Product'=>'PP-001']];$coverage=[['Blueprint ID'=>'5'],['Blueprint ID'=>'6']];
        $packet=['schema_version'=>1,'shop_key'=>'personalized_pod','business_key'=>'digicraftifygoods','catalog_key'=>'digicraftifygoods-master-500-v2','sources'=>[['id'=>'fixture','name'=>'offline.csv','sha256'=>str_repeat('e',64),'modified_at'=>'2026-11-03T00:00:00Z']],'sections'=>['concepts'=>['source_id'=>'fixture','sheet'=>'concepts','rows'=>$rows,'rows_sha256'=>ProductIntelligence::rowsHash($rows)],'coverage'=>['source_id'=>'fixture','sheet'=>'coverage','rows'=>$coverage,'rows_sha256'=>ProductIntelligence::rowsHash($coverage)]]];
        $saved=(new ProductIntelligenceRepository())->import($packet);self::assertFalse(is_wp_error($saved));$this->snapshot=$saved['snapshot_id'];
    }
    public function testInterruptedBatchRecoversReceiptWithoutSecondCollection(): void
    {
        $reader=new OfflinePrintifyResearchReader();$reader->interrupt=true;$batch=new PrintifyResearchBatch($reader);$plan=$batch->create($this->snapshot,[5,6]);$grant=str_repeat('a',64);
        self::assertWPError($batch->step($plan['batch_id'],$grant));self::assertSame(1,$reader->calls);
        $recovered=$batch->step($plan['batch_id'],$grant);self::assertSame('COLLECTED',$recovered['items'][0]['state']);self::assertSame(1,$reader->calls);self::assertSame(1,$reader->recoveries);
        $done=$batch->step($plan['batch_id'],$grant);self::assertSame('COLLECTED',$done['items'][1]['state']);self::assertSame(2,$reader->calls);
        $packet=(new ProductIntelligenceRepository())->read($done['items'][1]['snapshot_id']);self::assertCount(2,$packet['sections']['providers']['rows']);
        $export=ProductIntelligence::exportRows($packet,'providers',$done['items'][1]['snapshot_id']);self::assertSame(hash('sha256','raw-5'),$export[0]['_source_sha256']);self::assertSame(hash('sha256','raw-6'),$export[1]['_source_sha256']);self::assertSame('Printify catalog API',$export[1]['_sheet']);
        $batch->step($plan['batch_id'],$grant);self::assertSame(2,$reader->calls);
    }
    public function testRateLimitedAttemptIsNotRetriedOnResume(): void
    {
        $reader=new OfflinePrintifyResearchReader();$reader->rateLimit=true;$batch=new PrintifyResearchBatch($reader);$plan=$batch->create($this->snapshot,[5]);
        self::assertSame('catalog_read_account_rate_limit',$batch->step($plan['batch_id'],str_repeat('b',64))->get_error_code());
        self::assertWPError($batch->step($plan['batch_id'],str_repeat('b',64)));self::assertSame(1,$reader->calls);
    }
    public function testCrossShopGrantCannotDispatch(): void
    {
        $reader=new OfflinePrintifyResearchReader();$reader->shop='digital';$batch=new PrintifyResearchBatch($reader);$plan=$batch->create($this->snapshot,[5]);self::assertWPError($batch->step($plan['batch_id'],str_repeat('c',64)));self::assertSame(0,$reader->calls);
    }
    public function testUnreferencedBlueprintCannotBePrepared(): void
    {
        self::assertWPError((new PrintifyResearchBatch())->create($this->snapshot,[999]));
    }
    public function testDeletingProgressAuditFailsClosed(): void
    {
        $batch=new PrintifyResearchBatch(new OfflinePrintifyResearchReader());$plan=$batch->create($this->snapshot,[5]);global $wpdb;$wpdb->delete(\DigiForge\Database\Tables::audit_log(),['object_type'=>'pod_research_batch']);self::assertWPError($batch->status($plan['batch_id']));
    }
    public function testDeletedInterruptedMarkerCannotRestartWithAnotherGrant(): void
    {
        $reader=new OfflinePrintifyResearchReader();$reader->interrupt=true;$batch=new PrintifyResearchBatch($reader);$plan=$batch->create($this->snapshot,[5]);
        self::assertWPError($batch->step($plan['batch_id'],str_repeat('a',64)));delete_option('df_pod_research_batch_'.$plan['batch_id'].'_start_5');
        self::assertWPError($batch->step($plan['batch_id'],str_repeat('b',64)));self::assertSame(1,$reader->calls);
    }
    public function testAbsentGovernedPipelineDeniesBeforeTransport(): void
    {
        if(class_exists(\DigiForge\POD\PrintifyCatalogReadRepository::class)){$this->markTestSkipped('Only applies to the main baseline without the separate shared pipeline.');}
        self::assertSame('GOVERNED_PIPELINE_UNAVAILABLE',PrintifyResearchGateway::readiness()['pipeline_state']);self::assertWPError((new PrintifyResearchGateway())->collect(str_repeat('d',64),0,'offline-attempt'));
    }
    public function testMobilePanelExposesOnlyInertPreparationWithoutPipeline(): void
    {
        $_GET=['df_shop'=>'personalized_pod','df_pi_snapshot'=>$this->snapshot];ob_start();(new \DigiForge\Portal\ProductIntelligencePanel())->render('/portal/');$html=ob_get_clean();
        self::assertStringContainsString('Prepare local plan',$html);self::assertStringContainsString('Export research JSON',$html);
        if(!class_exists(\DigiForge\POD\PrintifyCatalogReadRepository::class))self::assertStringContainsString('disabled>Collect / resume one step',$html);
        if(($artifact=getenv('DIGIFORGE_PI_API_BROWSER_FIXTURE'))!==false)file_put_contents($artifact,'<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>'.file_get_contents(dirname(__DIR__,2).'/assets/portal.css').'</style></head><body><div class="df-portal-shell"><aside class="df-portal-sidebar">DigiForge · POD — Personalized</aside><main class="df-portal-main">'.$html.'</main></div></body></html>');
        $_GET=[];
    }
    public function testRestRequiresNonceCapabilitiesExactShopAndExplicitCollection(): void
    {
        $controller=new \DigiForge\REST\PrintifyResearchController();$r=new WP_REST_Request('GET','/digiforge/v1/shops/personalized_pod/printify-research/readiness');self::assertWPError($controller->permission($r));
        $r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));self::assertTrue($controller->permission($r));self::assertInstanceOf(WP_REST_Response::class,$controller->readiness($r));
        $post=new WP_REST_Request('POST','/digiforge/v1/shops/personalized_pod/printify-research/batches/'.str_repeat('a',64).'/step');$post->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$post->set_header('Content-Type','application/json');$post->set_url_params(['sha'=>str_repeat('a',64)]);$post->set_body(wp_json_encode(['grant_sha256'=>str_repeat('b',64),'confirm_catalog_get'=>false]));
        $denied=$controller->step($post);self::assertWPError($denied);self::assertSame('pod_research_explicit_collection_required',$denied->get_error_code());
        $role=get_role('administrator');$inherited=$role->has_cap('manage_digiforge_connections');$role->add_cap('manage_digiforge_connections');
        try{wp_get_current_user()->add_cap('manage_digiforge_connections',false);self::assertWPError($controller->permission($r));}
        finally{if(!$inherited)$role->remove_cap('manage_digiforge_connections');}
        $controller->register();do_action('rest_api_init');$routes=rest_get_server()->get_routes();self::assertArrayHasKey('/digiforge/v1/shops/personalized_pod/printify-research/readiness',$routes);self::assertArrayNotHasKey('/digiforge/v1/shops/digital/printify-research/readiness',$routes);
    }
}
