<?php
declare(strict_types=1);
/** Explicit isolated profile only; actual extracted core/companion, no mocked native ports. */
use DigiForge\Connector\Printify\{NativeBridge,NativePortContract,NativeReadOnlyInspection,NativeAuthorityAdapter,NativePanelAccessAdapter,NativeHeldJournal,NativeHeldJournalSchema,ShopBinding,CredentialReference,ExternalOperationIntent};
use DigiForge\IndependentAcceptance\NativeObservationConformance;
require_once DIGIFORGE_PATH.'tests/wordpress/fixtures/PrintifyApiApprovalFixture.php';
require_once __DIR__.'/../NativeObservationConformance.php';

final class NativeObserverCoreWordPressAcceptance extends WP_UnitTestCase
{
 private PrintifyApiApprovalFixture $fixture;private ShopBinding $scope;private array $context;private array $packet;private $network;private int $http=0;private array $journalSnapshots=[];
 protected function setUp():void {
  parent::setUp();remove_filter('query',[$this,'_create_temporary_tables']);remove_filter('query',[$this,'_drop_temporary_tables']);
  $this->fixture=new PrintifyApiApprovalFixture();$this->fixture->boot();
  foreach(['stop_all'=>true,'automation_armed'=>false,'activation_authorized'=>false]as $key=>$value)$this->fixture->setting($key,$value);
  self::assertTrue(DigiForge\Core\Settings::safety_locked());
  $v=PrintifyApiApprovalFixture::checked((new DigiForge\POD\ScopedProviderConnection())->verified('personalized_pod',$this->fixture->integration,'printify.connection.read'));
  $this->scope=new ShopBinding(2,'personalized_pod',$this->fixture->integration,$v['external_shop_reference'],$v['environment'],$v['review_revision'],new CredentialReference($this->fixture->integration),$v['binding_hash'],$v['expires_at']);
  $session=PrintifyApiApprovalFixture::checked(NativeAuthorityAdapter::session(1));
  $this->context=['version'=>'1.0.0','view'=>'unknown','session_fingerprint'=>$session['fingerprint'],'csrf_token'=>wp_create_nonce(NativePanelAccessAdapter::nonceAction($this->scope,'unknown'))];
  $bytes=file_get_contents('/private/core-conformance-observations.json');$this->packet=json_decode($bytes,true,32,JSON_THROW_ON_ERROR);
  self::assertSame('DF_SYNTHETIC_CORE_OBSERVATIONS_1',$this->packet['schema']);self::assertSame(NativeObservationConformance::CORE,$this->packet['core_sha']);
  $this->network=function(){++$this->http;throw new RuntimeException('Provider transport forbidden');};add_filter('pre_http_request',$this->network,PHP_INT_MAX);
 }
 protected function tearDown():void {
  remove_filter('pre_http_request',$this->network,PHP_INT_MAX);self::assertSame(0,$this->http);
  global $wpdb;if($this->journalSnapshots){$wpdb->query('COMMIT');foreach($this->journalSnapshots as $table=>$rows){$wpdb->query('DELETE FROM '.$table);foreach($rows as $row)$wpdb->insert($table,$row,array_fill(0,count($row),'%s'));}}
  self::assertTrue(DigiForge\Core\Settings::safety_locked());$this->fixture->restore();parent::tearDown();
 }
 private function binding():array {
  return array_replace($this->packet['binding'],['operation_id'=>hash('sha256',wp_generate_uuid4()),'scope_fingerprint'=>$this->scope->fingerprint(),'actor_id'=>1,'integration_id'=>$this->scope->integrationId,'credential_reference'=>$this->scope->integrationId,'provider_shop_id'=>$this->scope->providerShopId,'environment'=>$this->scope->environment,'authorization_revision'=>$this->scope->revision]);
 }
 private function readonlyCall(callable $call):mixed {
  $writes=0;$filter=static function(string $sql)use(&$writes):string{if(preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b/i',$sql))++$writes;return $sql;};add_filter('query',$filter);
  try{return $call();}finally{remove_filter('query',$filter);self::assertSame(0,$writes,'Conformance/native inspection must not write.');}
 }
 private function inspect(array $request=['kind'=>'READINESS']):array|WP_Error{return NativeReadOnlyInspection::snapshot($this->scope,1,$this->context,$request);}
 private function compare(array $native,array $binding,?array $observerBinding=null,int $offset=0,int $generationOffset=0):array {
  return NativeObservationConformance::compare(NativeBridge::connect('1.0.0')->contractManifest(),$native,$binding,$this->packet['observation'],$observerBinding??$this->packet['binding'],$this->packet['now']+$offset,$this->packet['generation']+$generationOffset);
 }
 private function deny(array|WP_Error $result):void{self::assertWPError($result);self::assertFalse($result->get_error_data()['external_execution_authorized']);self::assertFalse($result->get_error_data()['retry_permitted']);}
 public function testActualProtectedReadinessAndScopeDifferenceAreReportedWithoutPromotingEpochs():void {
  self::assertStringStartsWith(DIGIFORGE_PATH,(new ReflectionClass(NativeReadOnlyInspection::class))->getFileName());$core=NativeBridge::connect('1.0.0');self::assertInstanceOf(DigiForge\Connector\Printify\NativeCorePort::class,$core);
  self::assertSame(NativeObservationConformance::CORE,$core->contractManifest()['core_source_sha']);self::assertSame(['PANEL','GET_READ'],$core->contractManifest()['capabilities']);
  $this->readonlyCall(function():void{$native=$this->inspect();self::assertNotWPError($native);self::assertTrue($native['read_only']);self::assertNull($native['recovery_epoch']);self::assertFalse($native['independent_checkpoint_verified']);
   self::assertSame('12',$this->scope->providerShopId);self::assertSame('28930593',$this->packet['binding']['provider_shop_id']);
   $r=$this->compare($native,$this->binding());self::assertSame('READ_ONLY_INCOMPATIBLE',$r['status']);self::assertSame('MISMATCH',$r['scope_comparison']);self::assertContains('SCOPE_MISMATCH',$r['reasons']);self::assertNull($r['checkpoint_epoch']);self::assertFalse($r['dispatch_permitted']);
  });
 }
 public function testCallerAlignedFieldsCannotAuthenticateMinimizedObservationOrigin():void {
  $this->readonlyCall(function():void{$native=$this->inspect();self::assertNotWPError($native);$b=$this->binding();$r=$this->compare($native,$b,$b);self::assertSame('READ_ONLY_CONFORMANT',$r['status']);self::assertFalse($r['context_link_verified']);self::assertFalse($r['native_runtime_verified']);self::assertSame('NOT_PERFORMED_BY_COMPARATOR',$r['signature_verification']);self::assertFalse($r['independent_checkpoint_verified']);self::assertNull($r['recovery_epoch']);});
 }
 public function testSyntheticEvidenceCannotAuthorizeAnyCanonicalMutationOrReserveAlias():void {
  $this->readonlyCall(function():void{$core=NativeBridge::connect('1.0.0');$b=$this->binding();
   foreach(['AUTHORIZE','REGISTER','CLAIM','RECOVER','CANCEL','RECEIPT_READ','RECONCILE','OUTCOME','RESERVE']as $action){$q=['contract_version'=>'1.0.0','action'=>$action,'binding'=>$b,'context'=>['actor_id'=>1,'logical_reference'=>'synthetic-conformance','approval_reference'=>$b['approval_reference'],'authorization_revision'=>$b['authorization_revision'],'product_version'=>$b['product_version'],'expected_product_version'=>$b['expected_product_version']],'evidence_references'=>[$this->packet['observation']['observation_hash']],'duplicate_key'=>str_repeat('f',64),'data'=>['checkpoint_observation'=>$this->packet['observation']]];
    self::assertSame($action!=='RESERVE',NativePortContract::valid('mutation_request',$q));$r=$core->exchange($q);$this->deny($r);self::assertSame($action==='RESERVE'?'printify_native_mutation_contract':'printify_native_journal_and_independent_checkpoint_required',$r->get_error_code());
   }
  });
 }
 public function testPublicationOrderSubmissionAndMutationReadIntentsRemainDenied():void {
  $this->readonlyCall(function():void{$core=NativeBridge::connect('1.0.0');$this->deny($core->authorizePublication($this->binding(),['checkpoint_observation'=>$this->packet['observation']]));$this->deny($core->authorizeWorkflow($this->scope,1,'submit_order'));
   foreach(['PRODUCT_PUBLISH','ORDER_SUBMIT','ASSET_UPLOAD']as $op){try{new ExternalOperationIntent(1,'personalized_pod',$this->scope->integrationId,$op,[],str_repeat('a',32));self::fail('Mutation must not become a GET intent');}catch(InvalidArgumentException){self::assertTrue(true);}}
  });
 }
 public function testStaleOrRevokedGenerationReferencesDoNotBecomeNativeAuthority():void {
  $this->readonlyCall(function():void{$native=$this->inspect();self::assertNotWPError($native);$b=$this->binding();$stale=$this->compare($native,$b,$b,31);self::assertContains('OBSERVER_EXPIRED',$stale['reasons']);$revoked=$this->compare($native,$b,$b,0,1);self::assertContains('GENERATION_REFERENCE_MISMATCH',$revoked['reasons']);foreach([$stale,$revoked]as $r){self::assertFalse($r['mutation_authorized']);self::assertFalse($r['retry_permitted']);self::assertFalse($r['automatic_rearm']);}});
 }
 public function testExpiredNativeScopeAndWrongShopDenyActualInspection():void {
  $this->readonlyCall(function():void{
   $s=$this->scope;$expired=new ShopBinding($s->shopId,$s->shopKey,$s->integrationId,$s->providerShopId,$s->environment,$s->revision,$s->credential,$s->bindingHash,time()-1);self::assertWPError(NativeReadOnlyInspection::snapshot($expired,1,$this->context,['kind'=>'READINESS']));
   $wrong=new ShopBinding(3,'standard_pod',$s->integrationId,$s->providerShopId,$s->environment,$s->revision,$s->credential,$s->bindingHash,$s->expiresAt);self::assertWPError(NativeReadOnlyInspection::snapshot($wrong,1,$this->context,['kind'=>'READINESS']));
  });
 }
 public function testMissingJournalAndRevokedSessionCannotUseSyntheticEvidence():void {
  $this->readonlyCall(function():void{self::assertWPError($this->inspect(['kind'=>'HELD_OPERATION','operation_id'=>hash('sha256',wp_generate_uuid4())]));$r=$this->compare([],$this->binding());self::assertSame('READ_ONLY_INCOMPATIBLE',$r['status']);self::assertFalse($r['dispatch_permitted']);wp_set_current_user(0);self::assertWPError($this->inspect());});
 }
 public function testActualHeldUnknownInspectionCannotRearmOrDispatch():void {
  global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');foreach(NativeHeldJournalSchema::statements()as $sql)self::assertNotFalse($wpdb->query(preg_replace('/^CREATE TABLE /','CREATE TABLE IF NOT EXISTS ',$sql)));foreach(NativeHeldJournalSchema::tables()as $table)$this->journalSnapshots[$table]=$wpdb->get_results('SELECT * FROM '.$table,ARRAY_A);
  $b=$this->binding();$j=new NativeHeldJournal();self::assertNotWPError($j->prepare($b,hash('sha256','fixture-'.$b['operation_id'])));self::assertNotWPError($j->transition($b['operation_id'],'personalized_pod',1,1,'FENCE'));self::assertNotWPError($j->transition($b['operation_id'],'personalized_pod',1,2,'UNKNOWN'));
  $this->readonlyCall(function()use($j,$b):void{$native=$this->inspect(['kind'=>'HELD_OPERATION','operation_id'=>$b['operation_id']]);self::assertNotWPError($native);self::assertSame('UNKNOWN',$native['observation']['state']);$r=$this->compare($native,$b);self::assertSame('UNKNOWN',$r['native_state']);self::assertFalse($r['retry_permitted']);self::assertFalse($r['dispatch_permitted']);self::assertWPError($j->transition($b['operation_id'],'personalized_pod',1,3,'FENCE'));});
 }
}
