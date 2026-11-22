<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
require_once __DIR__.'/fixtures/NativeBusinessMutationFixture.php';
use DigiForge\Connector\Printify\{NativeExistingProductMutationEvidence as Evidence,NativeHeldJournalSchema,NativeHeldJournal,NativeBridge,NativePortContract};
use DigiForge\Database\Tables;
final class NativeExistingProductMutationEvidenceTest extends WP_UnitTestCase {
 use NativeBusinessMutationFixture;
 private PrintifyApiApprovalFixture $fixture;private array $snapshots=[];
 protected function setUp():void{parent::setUp();remove_filter('query',[$this,'_create_temporary_tables']);remove_filter('query',[$this,'_drop_temporary_tables']);PortalAccessFixture::reset();DigiForge\Core\Activator::activate();$this->fixture=new PrintifyApiApprovalFixture();$this->fixture->boot();global $wpdb;foreach([Tables::opportunities(),Tables::product_families(),Tables::products(),Tables::product_versions(),Tables::production_plans(),Tables::pod_mappings(),Tables::pod_business_mappings(),Tables::asset_specs(),Tables::production_plan_assets()]as $t)$this->snapshots[$t]=$wpdb->get_results('SELECT * FROM '.$t,ARRAY_A);DigiForge\Core\Settings::protectProduction();}
 protected function tearDown():void{try{global $wpdb;$wpdb->query('ROLLBACK');foreach(NativeHeldJournalSchema::tables()as $t)$wpdb->query('DROP TABLE IF EXISTS '.$t);foreach($this->snapshots as $t=>$rows){$wpdb->query('DELETE FROM '.$t);foreach($rows as $r)$wpdb->insert($t,$r,array_fill(0,count($r),'%s'));}$this->fixture->restore();}finally{parent::tearDown();}}
 public function testActualObservedProductAdoptionAndReviewedPriceUpdateRemainNonexecuting():void {
  self::assertTrue(class_exists(Evidence::class),'Protected existing-product update source resolver required.');
  $f=$this->businessMutationFixture($this->fixture,true);$scope=$f['scope'];$pipeline=new DigiForge\POD\PrintifyProductPipeline();$image=PrintifyApiApprovalFixture::checked($pipeline->plan($f['plan_hash']));$selection=$image['selection'];$this->fixture->refreshOwnerVerification();foreach(['activation_authorized'=>true,'automation_armed'=>true,'stop_all'=>false,'printify_activation_authorized'=>true,'printify'=>true]as $key=>$value)$this->fixture->setting($key,$value);
  $read=new DigiForge\POD\PrintifyApiReadWorkflow();$p=PrintifyApiApprovalFixture::checked($read->prepare('personalized_pod',$scope->integrationId,'PRODUCT_READ',['product_id'=>'synthetic-product','blueprint_id'=>$selection['blueprint_id'],'provider_id'=>$selection['provider_id'],'variant_ids'=>[$selection['variant_id']]]));$auth=$this->fixture->approve($read,$p,'printify-api-read');$body=['id'=>'synthetic-product','shop_id'=>$scope->providerShopId,'blueprint_id'=>$selection['blueprint_id'],'print_provider_id'=>$selection['provider_id'],'variants'=>[['id'=>$selection['variant_id'],'price'=>1899,'is_enabled'=>true]],'print_areas'=>[['variant_ids'=>[$selection['variant_id']],'placeholders'=>[['position'=>'front','images'=>[]]]]],'images'=>[]];$calls=0;$observed=PrintifyApiApprovalFixture::checked((new DigiForge\POD\PrintifyControlledTransport(static function($url,$args)use($body,&$calls,$scope){++$calls;self::assertSame('GET',$args['method']);self::assertSame('https://api.printify.com/v1/shops/'.$scope->providerShopId.'/products/synthetic-product.json',$url);return PrintifyApiApprovalFixture::response($body);}))->executeApiRead($p['hash'],$auth['hash']));self::assertSame(1,$calls);
  $adopted=PrintifyApiApprovalFixture::checked($pipeline->adoptReadReceipt($image['workflow'],$f['package_hash'],$scope->integrationId,$observed['hash']));$update=PrintifyApiApprovalFixture::checked($pipeline->prepare($image['workflow'],$f['package_hash'],$scope->integrationId,'PRODUCT_UPDATE',['product_receipt'=>$adopted['hash'],'price_minor'=>2199]));$policy=PrintifyApiApprovalFixture::checked(DigiForge\Security\ReviewedLocalEvidence::propose('policy','personalized_pod','printify-product:'.$update['hash'],PrintifyApiApprovalFixture::checked($pipeline->approvalPayload($update['hash'])),time(),time()+1200,wp_generate_uuid4(),0));PortalAccessFixture::login($this->fixture->reviewer);PrintifyApiApprovalFixture::checked(DigiForge\Security\ReviewedLocalEvidence::decide('policy','personalized_pod','printify-product:'.$update['hash'],$policy['revision'],$policy['proposal_hash'],'APPROVED',wp_generate_uuid4()));$this->fixture->owner();DigiForge\Core\Settings::protectProduction();
  $deny=static function(){throw new RuntimeException('No HTTP during update source inspection');};add_filter('pre_http_request',$deny,PHP_INT_MAX);
  try{
   $r=PrintifyApiApprovalFixture::checked(Evidence::resolve($scope,1,$f['mapping_id'],$f['spec_id'],$update['hash'],$observed['hash']));self::assertSame('PRODUCT_UPDATE',$r['operation']);self::assertSame('synthetic-product',$r['provider_product_id']);self::assertSame($selection['variant_id'],$r['variant_id']);self::assertSame($f['business_version_id'],$r['product_version_id']);self::assertSame($adopted['hash'],$r['adopted_product_receipt_hash']);self::assertSame($observed['hash'],$r['product_read_receipt_hash']);self::assertSame(2199,$r['price_minor']);self::assertFalse($r['provider_currentness_verified']);self::assertTrue($r['revalidation_before_mutation_required']);self::assertFalse($r['provider_version_cas_verified']);self::assertFalse($r['mutation_authorized']);self::assertFalse($r['dispatch_permitted']);self::assertNull($r['native_product_version']);self::assertArrayNotHasKey('body',$r);self::assertArrayNotHasKey('contents',$r);self::assertSame(hash('sha256','{"body":{"variants":[{"id":'.$selection['variant_id'].',"is_enabled":true,"price":2199}]},"endpoint":"\/v1\/shops\/'.$scope->providerShopId.'\/products\/synthetic-product.json","method":"PUT"}'),$r['payload_fingerprint']);self::assertSame(1,$calls);
   $this->fixture->refreshOwnerVerification();
   $proposal=PrintifyApiApprovalFixture::checked(Evidence::proposeRevision($scope,1,$f['mapping_id'],$f['spec_id'],
    $update['hash'],$observed['hash'],0,time(),time()+1200,wp_generate_uuid4()));
   self::assertSame('PENDING',$proposal['status']);
   PortalAccessFixture::login($this->fixture->reviewer);
   $review=PrintifyApiApprovalFixture::checked(Evidence::decideRevision($scope,$this->fixture->reviewer,'synthetic-product',
    $proposal['revision'],$proposal['proposal_hash'],'APPROVED',wp_generate_uuid4()));
   $this->fixture->owner();
   $current=PrintifyApiApprovalFixture::checked(Evidence::currentRevision($scope,1,$f['mapping_id'],$f['spec_id'],
    $update['hash'],$observed['hash'],1));
   self::assertSame(1,$current['core_revision']);
   self::assertSame($r['payload_fingerprint'],$current['source']['payload_fingerprint']);
   self::assertNull($current['native_product_version']);
   self::assertFalse($current['dispatch_permitted']);
   global $wpdb;
   foreach (NativeHeldJournalSchema::statements() as $sql) {
    self::assertNotFalse($wpdb->query(preg_replace('/^CREATE TABLE /','CREATE TABLE IF NOT EXISTS ',$sql)));
   }
   require_once __DIR__.'/../support/NativePrintifyContractFixture.php';
   $port=PrintifyApiApprovalFixture::checked(NativeBridge::connect('1.0.0'));
   $key=NativePortContract::hash(['DF_CORE_PROVIDER_PRODUCT_UPDATE_1',$scope->shopKey,$scope->integrationId,
    $scope->providerShopId,$scope->environment,'synthetic-product',1]);
   $intent=NativePortContract::hash(['DF_CORE_PROVIDER_PRODUCT_INTENT_1',$scope->fingerprint(),1,$current]);
   $context=['actor_id'=>1,'logical_reference'=>'product-source:'.$f['mapping_id'].':'.$f['spec_id'],
    'authorization_revision'=>$scope->revision,'approval_reference'=>$r['plan_policy_hash'],
    'product_version'=>0,'expected_product_version'=>0];
   $binding=array_diff_key($context,['logical_reference'=>true])+[
    'operation_id'=>NativePortContract::hash([$key,$r['payload_fingerprint'],$intent]),
    'intent_fingerprint'=>$intent,'payload_fingerprint'=>$r['payload_fingerprint'],'scope_fingerprint'=>$scope->fingerprint(),
    'shop_id'=>$scope->shopId,'shop_key'=>$scope->shopKey,'integration_id'=>$scope->integrationId,
    'credential_reference'=>$scope->credential->integrationId,'provider_shop_id'=>$scope->providerShopId,'environment'=>$scope->environment,
   ];
   $request=['contract_version'=>'1.0.0','action'=>'REGISTER','binding'=>$binding,'context'=>$context,
    'evidence_references'=>[$update['hash'],$observed['hash'],$current['review_hash']],
    'duplicate_key'=>$key,'data'=>['reviewed_product_version'=>1]];
   $writerStarts=0;
   $countWriter=static function(string $sql) use (&$writerStarts): string {
    if ($sql==='START TRANSACTION') ++$writerStarts;
    return $sql;
   };
   add_filter('query',$countWriter);
   try {
    foreach (['REGISTER','CLAIM'] as $action) {
     $denial=$port->exchange(array_replace($request,['action'=>$action]));
     self::assertWPError($denial);
     self::assertSame('printify_native_journal_and_independent_checkpoint_required',$denial->get_error_code());
    }
    self::assertSame(2,$writerStarts);
    $wrong=$request;
    $wrong['binding']['payload_fingerprint']=str_repeat('a',64);
    self::assertWPError($port->exchange($wrong));
    self::assertSame(2,$writerStarts);
    $table=NativeHeldJournalSchema::tables()[0];
    self::assertNull($wpdb->get_var($wpdb->prepare("SELECT operation_id FROM $table WHERE operation_id=%s",$binding['operation_id'])));
   } finally {remove_filter('query',$countWriter);}
   // Run the independent rejection probes after the time-bounded writer checks.
   self::assertWPError(Evidence::resolve($scope,1,$f['mapping_id'],$f['spec_id'],$f['plan_hash'],$observed['hash']));self::assertWPError(Evidence::resolve($scope,1,$f['mapping_id'],$f['spec_id'],$update['hash'],str_repeat('a',64)));self::assertWPError(Evidence::resolve($scope,1,$f['mapping_id'],$f['spec_id'],$update['hash'],$observed['hash'],str_repeat('f',64)));
   self::assertWPError(Evidence::currentRevision($scope,1,$f['mapping_id'],$f['spec_id'],$update['hash'],$observed['hash'],2));
   PortalAccessFixture::login($this->fixture->reviewer);
   self::assertWPError(Evidence::decideRevision($scope,$this->fixture->reviewer,'foreign-product',$review['revision'],
    $proposal['proposal_hash'],'REVOKED',wp_generate_uuid4()));
   PrintifyApiApprovalFixture::checked(Evidence::decideRevision($scope,$this->fixture->reviewer,'synthetic-product',
    $review['revision'],$proposal['proposal_hash'],'REVOKED',wp_generate_uuid4()));
   $this->fixture->owner();
   self::assertWPError(Evidence::currentRevision($scope,1,$f['mapping_id'],$f['spec_id'],$update['hash'],$observed['hash'],1));
   global $wpdb;$audit=$r['adoption_producer_audit_id'];self::assertSame(1,$wpdb->update(Tables::audit_log(),['actor_id'=>$this->fixture->reviewer],['id'=>$audit],['%d'],['%d']));self::assertWPError(Evidence::resolve($scope,1,$f['mapping_id'],$f['spec_id'],$update['hash'],$observed['hash']));self::assertSame(1,$wpdb->update(Tables::audit_log(),['actor_id'=>1],['id'=>$audit],['%d'],['%d']));
   $mapping=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_mappings().' WHERE id=%d',$f['mapping_id']),ARRAY_A);self::assertSame(1,$wpdb->update(Tables::pod_mappings(),['provider_product_key'=>'foreign-product'],['id'=>$f['mapping_id']],['%s'],['%d']));self::assertWPError(Evidence::resolve($scope,1,$f['mapping_id'],$f['spec_id'],$update['hash'],$observed['hash']));self::assertSame(1,$wpdb->update(Tables::pod_mappings(),['provider_product_key'=>$mapping['provider_product_key']],['id'=>$f['mapping_id']],['%s'],['%d']));
   PortalAccessFixture::login($this->fixture->reviewer);PrintifyApiApprovalFixture::checked(DigiForge\Security\ReviewedLocalEvidence::decide('policy','personalized_pod','printify-api-read:'.$p['hash'],2,$auth['record']['policy_hash'],'REVOKED',wp_generate_uuid4()));$this->fixture->owner();self::assertWPError(Evidence::resolve($scope,1,$f['mapping_id'],$f['spec_id'],$update['hash'],$observed['hash']));self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertSame(1,$calls);
  }finally{remove_filter('pre_http_request',$deny,PHP_INT_MAX);}
 }
}
