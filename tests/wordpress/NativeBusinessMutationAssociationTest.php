<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
require_once __DIR__.'/fixtures/NativeBusinessMutationFixture.php';
require_once __DIR__.'/fixtures/NativeProductRevisionFixture.php';
use DigiForge\Connector\Printify\{NativeBusinessMutationAssociation as Association,NativeHeldJournalSchema,NativeHeldJournal,NativeBridge};
use DigiForge\Database\Tables;
final class NativeBusinessMutationAssociationTest extends WP_UnitTestCase {
 use NativeBusinessMutationFixture;
 private PrintifyApiApprovalFixture $fixture;private array $snapshots=[];
 protected function setUp():void{parent::setUp();remove_filter('query',[$this,'_create_temporary_tables']);remove_filter('query',[$this,'_drop_temporary_tables']);PortalAccessFixture::reset();DigiForge\Core\Activator::activate();$this->fixture=new PrintifyApiApprovalFixture();$this->fixture->boot();global $wpdb;foreach([Tables::opportunities(),Tables::product_families(),Tables::products(),Tables::product_versions(),Tables::production_plans(),Tables::pod_mappings(),Tables::pod_business_mappings(),Tables::asset_specs(),Tables::production_plan_assets()]as $t)$this->snapshots[$t]=$wpdb->get_results('SELECT * FROM '.$t,ARRAY_A);DigiForge\Core\Settings::protectProduction();}
 protected function tearDown():void{try{global $wpdb;$wpdb->query('ROLLBACK');$wpdb->query('DROP TABLE IF EXISTS '.NativeProductRevisionFixture::table());foreach(NativeHeldJournalSchema::tables()as $t)$wpdb->query('DROP TABLE IF EXISTS '.$t);foreach($this->snapshots as $t=>$rows){$wpdb->query('DELETE FROM '.$t);foreach($rows as $r)$wpdb->insert($t,$r,array_fill(0,count($r),'%s'));}$this->fixture->restore();}finally{parent::tearDown();}}
 public function testExactIndependentSourceAssociationDoesNotGrantDispatchOrNativeCounters():void {
  self::assertTrue(class_exists(Association::class),'Core-owned independently reviewed source association producer required.');
  $f=$this->businessMutationFixture($this->fixture);$scope=$f['scope'];$m=$f['mapping_id'];$s=$f['spec_id'];$p=$f['plan_hash'];
  $deny=static function(){throw new RuntimeException('No provider HTTP');};add_filter('pre_http_request',$deny,PHP_INT_MAX);
  try {
   $source=PrintifyApiApprovalFixture::checked(Association::source($scope,1,$m,$s,$p));
   self::assertWPError(Association::read($scope,1,$m,$s,$p));
   $proposal=PrintifyApiApprovalFixture::checked(Association::propose($scope,1,$m,$s,$p,$source['source_hash'],0,time(),time()+600,wp_generate_uuid4()));
   self::assertSame('PENDING',$proposal['status']);self::assertSame(1,$proposal['version']);
   self::assertWPError(Association::decide($scope,1,$m,$s,$p,1,$proposal['proposal_hash'],'APPROVED',wp_generate_uuid4()));
   PortalAccessFixture::login($this->fixture->reviewer);
   $review=PrintifyApiApprovalFixture::checked(Association::decide($scope,$this->fixture->reviewer,$m,$s,$p,1,$proposal['proposal_hash'],'APPROVED',wp_generate_uuid4()));self::assertSame('APPROVED',$review['status']);
   $this->fixture->owner();$a=PrintifyApiApprovalFixture::checked(Association::read($scope,1,$m,$s,$p));self::assertSame(1,$a['association_version']);self::assertSame(2,$a['review_revision']);self::assertTrue($a['business_package_binding_approved']);self::assertFalse($a['source_approval_cas_verified']);self::assertNull($a['native_product_version']);self::assertFalse($a['mutation_authorized']);self::assertFalse($a['dispatch_permitted']);
   self::assertArrayHasKey('current_source_evidence_hash',$a,'Use the exact already validated current source, never an unverified cached association.');
   $e=PrintifyApiApprovalFixture::checked(DigiForge\Connector\Printify\NativeBusinessMutationEvidence::resolve($scope,1,$m,$s,$p));
   self::assertSame($e['evidence_hash'],$a['current_source_evidence_hash']);
   $wire=PrintifyApiApprovalFixture::checked(Association::uploadDescriptor($scope,1,$m,$s,$p,$e['evidence_hash']));self::assertSame('DF_NATIVE_IMAGE_UPLOAD_WIRE_1',$wire['schema']);self::assertSame('shop2-'.substr($f['package_hash'],0,16).'.png',$wire['file_name']);self::assertSame($e['payload_fingerprint'],$wire['payload_fingerprint']);self::assertArrayNotHasKey('contents',$wire);self::assertArrayNotHasKey('body',$wire);self::assertSame('/v1/uploads/images.json',$wire['endpoint']);
   self::assertWPError(Association::propose($scope,1,$m,$s,$p,str_repeat('f',64),2,time(),time()+600,wp_generate_uuid4()));
   $next=PrintifyApiApprovalFixture::checked(Association::propose($scope,1,$m,$s,$p,$source['source_hash'],2,time(),time()+600,wp_generate_uuid4()));self::assertSame(2,$next['version']);self::assertWPError(Association::read($scope,1,$m,$s,$p));
   PortalAccessFixture::login($this->fixture->reviewer);PrintifyApiApprovalFixture::checked(Association::decide($scope,$this->fixture->reviewer,$m,$s,$p,3,$next['proposal_hash'],'APPROVED',wp_generate_uuid4()));$this->fixture->owner();$a=PrintifyApiApprovalFixture::checked(Association::read($scope,1,$m,$s,$p));self::assertSame(2,$a['association_version']);
   global $wpdb;self::assertSame(1,$wpdb->update(Tables::product_versions(),['notes'=>'changed association source'],['id'=>$f['business_version_id']],['%s'],['%d']));self::assertWPError(Association::read($scope,1,$m,$s,$p));self::assertWPError(Association::uploadDescriptor($scope,1,$m,$s,$p,$e['evidence_hash']));
   PortalAccessFixture::login($this->fixture->reviewer);$r=PrintifyApiApprovalFixture::checked(Association::decide($scope,$this->fixture->reviewer,$m,$s,$p,4,$next['proposal_hash'],'REVOKED',wp_generate_uuid4()));self::assertSame('REVOKED',$r['status']);$this->fixture->owner();self::assertWPError(Association::read($scope,1,$m,$s,$p));self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertTrue(DigiForge\Core\Settings::safety_locked());
  } finally {remove_filter('pre_http_request',$deny,PHP_INT_MAX);}
 }
 public function testWriterJoinsCurrentSourceAndRejectsStaleOrRevokedReview():void {
  global $wpdb;
  $f=$this->businessMutationFixture($this->fixture);
  $scope=$f['scope'];$m=$f['mapping_id'];$s=$f['spec_id'];$p=$f['plan_hash'];
  $source=PrintifyApiApprovalFixture::checked(Association::source($scope,1,$m,$s,$p));
  $proposal=PrintifyApiApprovalFixture::checked(Association::propose($scope,1,$m,$s,$p,$source['source_hash'],0,time(),time()+600,wp_generate_uuid4()));
  PortalAccessFixture::login($this->fixture->reviewer);
  PrintifyApiApprovalFixture::checked(Association::decide($scope,$this->fixture->reviewer,$m,$s,$p,1,$proposal['proposal_hash'],'APPROVED',wp_generate_uuid4()));
  $this->fixture->owner();
  $join=static fn(int $revision=2,?string $payload=null)=>Association::lockForWriter($scope,1,$m,$s,$p,$proposal['proposal_hash'],$revision,$payload??$source['payload_fingerprint']);
  self::assertWPError($join(),'The join requires a caller-owned writer transaction.');
  self::assertNotFalse($wpdb->query('START TRANSACTION'));
  try {
   $locked=PrintifyApiApprovalFixture::checked($join());
   self::assertSame($source['product_version_id'],$locked['product_version_id']);
   self::assertSame($source['source_hash'],$locked['source_hash']);
   self::assertFalse($locked['dispatch_permitted']);
   foreach ([$join(1), $join(2,str_repeat('f',64))] as $rejected) {
    self::assertWPError($rejected);
    self::assertSame('printify_native_association_writer_binding',$rejected->get_error_code());
   }
   self::assertWPError(Association::lockForWriter($scope,$this->fixture->reviewer,$m,$s,$p,$proposal['proposal_hash'],2,$source['payload_fingerprint']));
   self::assertSame(1,$wpdb->update(Tables::product_versions(),['notes'=>'changed during writer admission'],['id'=>$f['business_version_id']]));
   self::assertWPError($join());
  } finally {$wpdb->query('ROLLBACK');}
  self::assertNotFalse($wpdb->query('START TRANSACTION'));
  try {self::assertTrue(is_array($join()),'Rolling back source drift restores the original reviewed source.');}
  finally {$wpdb->query('ROLLBACK');}

  self::assertNotFalse($wpdb->query(NativeProductRevisionFixture::migration()));
  try {
   self::assertNotFalse($wpdb->query('START TRANSACTION'));
   $writer=PrintifyApiApprovalFixture::checked($join());
   $product=$writer['product_id'];
   $digest=$writer['source_hash'];
   self::assertTrue(NativeProductRevisionFixture::advance($scope,$product,0,$digest));
   self::assertFalse(NativeProductRevisionFixture::advance($scope,$product,0,$digest));
   self::assertTrue(NativeProductRevisionFixture::lockCurrent($scope,$product,1,$digest));
   self::assertFalse(NativeProductRevisionFixture::lockCurrent($scope,$product,2,$digest));
   self::assertFalse(NativeProductRevisionFixture::lockCurrent($scope,$product+1,1,$digest));
   self::assertNotFalse($wpdb->query('COMMIT'));

   self::assertNotFalse($wpdb->query('START TRANSACTION'));
   $nextDigest=str_repeat('d',64);
   self::assertTrue(NativeProductRevisionFixture::advance($scope,$product,1,$nextDigest));
   self::assertFalse(NativeProductRevisionFixture::advance($scope,$product,1,$digest));
   self::assertFalse(NativeProductRevisionFixture::lockCurrent($scope,$product,1,$digest));
   self::assertTrue(NativeProductRevisionFixture::lockCurrent($scope,$product,2,$nextDigest));
   self::assertNotFalse($wpdb->query('ROLLBACK'));
   self::assertNotFalse($wpdb->query('START TRANSACTION'));
   self::assertTrue(NativeProductRevisionFixture::lockCurrent($scope,$product,1,$digest));
   self::assertNotFalse($wpdb->query('ROLLBACK'));
  } finally {
   $wpdb->query('ROLLBACK');
  }

  foreach(NativeHeldJournalSchema::statements() as $sql) self::assertNotFalse($wpdb->query(preg_replace('/^CREATE TABLE /','CREATE TABLE IF NOT EXISTS ',$sql)));
  $this->fixture->refreshOwnerVerification();
  $evidence=PrintifyApiApprovalFixture::checked(Association::read($scope,1,$m,$s,$p));
  $transition=static fn(?int $revision=null,?string $head=null)=>DigiForge\Connector\Printify\NativeBusinessMutationEvidence::transitionReviewedHold(
   $scope,1,$m,$s,$p,$evidence['current_source_evidence_hash'],$proposal['proposal_hash'],2,$revision,$head);
  $httpAttempts=0;
  $deny=static function()use(&$httpAttempts){++$httpAttempts;throw new RuntimeException('No provider HTTP');};
  add_filter('pre_http_request',$deny,PHP_INT_MAX);
  try {
   $held=PrintifyApiApprovalFixture::checked($transition());
   self::assertSame('HELD',$held['record']['state']);
   self::assertArrayHasKey('association_pin',$held['record']);
   $journal=new NativeHeldJournal();
   $binding=$held['record']['binding'];
   foreach (['actor_id'=>$this->fixture->reviewer,'provider_shop_id'=>'999','shop_key'=>'standard_pod','payload_fingerprint'=>str_repeat('e',64)] as $field=>$wrong) {
    self::assertWPError($journal->fenceScoped(array_replace($binding,[$field=>$wrong]),1,$held['event_hash']));
   }
   self::assertWPError($journal->prepareScoped($binding,$held['record']['candidate_key']));
   self::assertWPError($journal->fenceScoped($binding,1,$held['event_hash']), 'A plain scoped fence cannot skip the association.');
   $pin=['mapping'=>$m,'spec'=>$s,'plan'=>$p,'review'=>$proposal['proposal_hash'],'revision'=>2,'source'=>$source['source_hash']];
   $wrongPin=array_replace($pin,['source'=>str_repeat('f',64)]);
   self::assertWPError($journal->fenceScoped($binding,1,$held['event_hash'],$wrongPin));
   $fenced=PrintifyApiApprovalFixture::checked($transition(1,$held['event_hash']));
   self::assertSame('FENCED',$fenced['record']['state']);
   self::assertSame($held['record']['association_pin'],$fenced['record']['association_pin']);
   self::assertFalse($fenced['record']['dispatch_permitted']);
   self::assertWPError($journal->fenceScoped($binding,1,$held['event_hash'],$pin));
   $unknown=PrintifyApiApprovalFixture::checked($journal->transitionPinned($binding['operation_id'],$scope->shopKey,1,2,$fenced['event_hash'],$fenced['record']['fence_hash'],'UNKNOWN'));
   self::assertSame('UNKNOWN',$unknown['record']['state']);
   self::assertSame($unknown,(new NativeHeldJournal())->read($binding['operation_id'],$scope->shopKey,1));
   self::assertWPError($journal->fenceScoped($binding,3,$unknown['event_hash'],$pin));
   if (!interface_exists(DigiForgePrintifyConnector\Preparation\NativeCorePortInterface::class)) {
    require_once __DIR__.'/../fixtures/native-printify-companion/NativeCorePortInterface.php';
   }
   $port=PrintifyApiApprovalFixture::checked(NativeBridge::connect('1.0.0'));
   $request=['contract_version'=>'1.0.0','action'=>'REGISTER','binding'=>$binding,'context'=>[
    'actor_id'=>1,'logical_reference'=>'product-source:'.$m.':'.$s,
    'approval_reference'=>$binding['approval_reference'],'authorization_revision'=>$binding['authorization_revision'],
    'product_version'=>0,'expected_product_version'=>0],
    'evidence_references'=>[$p,$proposal['proposal_hash']],'duplicate_key'=>$held['record']['candidate_key'],'data'=>[]];
   $writerStarts=0;
   $writes=0;
   $observe=static function(string $sql)use(&$writerStarts,&$writes):string {
    if ($sql==='START TRANSACTION') ++$writerStarts;
    if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i',$sql)) ++$writes;
    return $sql;
   };
   add_filter('query',$observe);
   try {
    foreach (['REGISTER','CLAIM'] as $action) {
     $denial=$port->exchange(array_replace($request,['action'=>$action]));
     self::assertWPError($denial);
     self::assertSame('printify_native_journal_and_independent_checkpoint_required',$denial->get_error_code());
    }
    self::assertSame(2,$writerStarts);
    self::assertSame(0,$writes);
    foreach (['operation_id','intent_fingerprint','payload_fingerprint'] as $field) {
     $substituted=$request;
     $substituted['binding'][$field]=str_repeat('f',64);
     $denial=$port->exchange($substituted);
     self::assertWPError($denial);
     self::assertSame('printify_native_canonical_product_intent_mismatch',$denial->get_error_code());
    }
    self::assertWPError($port->exchange(array_replace($request,['duplicate_key'=>str_repeat('f',64)])));
    $nativeRevision=$request;
    foreach (['product_version','expected_product_version'] as $field) {
     $nativeRevision['binding'][$field]=1;
     $nativeRevision['context'][$field]=1;
    }
    foreach (['REGISTER','CLAIM'] as $action) {
     $denial=$port->exchange(array_replace($nativeRevision,['action'=>$action]));
     self::assertWPError($denial);
     self::assertSame('printify_native_product_revision_authority_required',$denial->get_error_code());
    }
    self::assertSame(2,$writerStarts,'Substituted identities cannot reach a writer transaction.');
   } finally {remove_filter('query',$observe);}
   $receipt=$port->exchange(array_replace($request,['action'=>'RECEIPT_READ','data'=>['receipt_reference'=>$unknown['event_hash']]]));
   self::assertWPError($receipt);
   self::assertSame('UNKNOWN',$receipt->get_error_data()['outcome']);
   self::assertFalse($receipt->get_error_data()['retry_permitted']);
   self::assertSame(0,$httpAttempts);
  } finally {remove_filter('pre_http_request',$deny,PHP_INT_MAX);}
  PortalAccessFixture::login($this->fixture->reviewer);
  PrintifyApiApprovalFixture::checked(Association::decide($scope,$this->fixture->reviewer,$m,$s,$p,2,$proposal['proposal_hash'],'REVOKED',wp_generate_uuid4()));
  $this->fixture->owner();
  self::assertWPError($port->exchange($request),'A revoked review cannot reach the canonical writer.');
  self::assertNotFalse($wpdb->query('START TRANSACTION'));
  try {
   $revoked=$join();
   self::assertWPError($revoked);
   self::assertSame('digiforge_local_review_not_currently_approved',$revoked->get_error_code());
  }
  finally {$wpdb->query('ROLLBACK');}
 }
 public function testMissingSessionCannotProbeBusinessSourceLocator():void {
  $v=PrintifyApiApprovalFixture::checked((new DigiForge\POD\ScopedProviderConnection())->verified('personalized_pod',$this->fixture->integration,'printify.connection.read'));$scope=new DigiForge\Connector\Printify\ShopBinding(2,'personalized_pod',$this->fixture->integration,$v['external_shop_reference'],$v['environment'],$v['review_revision'],new DigiForge\Connector\Printify\CredentialReference($this->fixture->integration),$v['binding_hash'],$v['expires_at']);$probed=false;
  $filter=static function(string $sql)use(&$probed):string{if(str_contains($sql,'FROM '.Tables::pod_mappings()))$probed=true;return $sql;};add_filter('query',$filter,PHP_INT_MIN);
  try{self::assertWPError(Association::read($scope,0,1234,1234,str_repeat('a',64)));self::assertFalse($probed,'No business mapping query may precede current scoped/session authorization.');}finally{remove_filter('query',$filter,PHP_INT_MIN);}
 }

}
