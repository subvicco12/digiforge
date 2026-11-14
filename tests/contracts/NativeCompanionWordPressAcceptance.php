<?php
declare(strict_types=1);
/** Run explicitly in a composed isolated actual-core artifact, not the legacy control. */
use DigiForge\Connector\Printify\{NativeBridge,NativePanelAccessAdapter,NativePortContract,ShopBinding,CredentialReference,ExternalOperationIntent,NativeHeldJournal,NativeHeldJournalSchema};
use DigiForgePrintifyConnector\Preparation\{NativePortAdapter,NativeCorePortInterface,NativePortSchema,ProtectedPanel,RecoveryDiagnostics};
require_once DIGIFORGE_PATH.'tests/wordpress/fixtures/PortalAccessFixture.php';
require_once DIGIFORGE_PATH.'tests/wordpress/Support/ReviewedLocalFixture.php';
require_once DIGIFORGE_PATH.'tests/wordpress/Support/ReviewedOwnershipFixture.php';
require_once DIGIFORGE_PATH.'tests/wordpress/Support/ScopedProviderConnectionFixture.php';
final class NativeCompanionWordPressAcceptance extends WP_UnitTestCase {
 private int $http=0;private int $ciphertext=0;private $httpGuard;private $queryGuard;
 protected function setUp():void {
  parent::setUp();remove_filter('query',[$this,'_create_temporary_tables']);remove_filter('query',[$this,'_drop_temporary_tables']);PortalAccessFixture::reset();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();
  $this->httpGuard=function(){++$this->http;throw new RuntimeException('External HTTP prohibited');};
  $this->queryGuard=function(string $sql):string{if(preg_match('/^\s*SELECT\s/i',$sql)&&str_contains($sql,DigiForge\Database\Tables::integration_secrets())&&preg_match('/\bciphertext\b|\*/i',substr($sql,0,(int)stripos($sql,'FROM')))){++$this->ciphertext;throw new RuntimeException('Credential ciphertext access prohibited');}return $sql;};
  add_filter('pre_http_request',$this->httpGuard,PHP_INT_MAX);add_filter('query',$this->queryGuard,PHP_INT_MAX);
 }
 protected function tearDown():void {
  remove_filter('pre_http_request',$this->httpGuard,PHP_INT_MAX);remove_filter('query',$this->queryGuard,PHP_INT_MAX);self::assertSame(0,$this->http);self::assertSame(0,$this->ciphertext);self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertTrue(DigiForge\Core\Settings::safety_locked());
  global $wpdb;$wpdb->query('ROLLBACK');$wpdb->query('SET autocommit=0');PortalAccessFixture::reset();parent::tearDown();
 }
 private function scope():ShopBinding {
  $actors=ReviewedLocalFixture::actors('personalized_pod');$owner=$actors['author'];$old=ReviewedOwnershipFixture::create($this);$id=$old['integration_id'];$repo=new DigiForge\Integrations\Repository();
  // Only synthetic fixture material is written, never read/decrypted by these tests.
  self::assertTrue($repo->storeSecret($id,'personal_access_token','SYNTHETIC-BATCH9-NON-CREDENTIAL'));self::assertNotWPError($repo->setEnabled($id,true));ScopedProviderConnectionFixture::approve($id,$owner,null,'printify.connection.read');
  ShopAccessFixture::authorizeActor($owner,['personalized_pod'],['manage_digiforge','manage_digiforge_settings','manage_digiforge_connections','manage_digiforge_products','manage_digiforge_pod','manage_digiforge_orders','manage_digiforge_listings','manage_digiforge_research']);PortalAccessFixture::login($owner);
  $v=(new DigiForge\POD\ScopedProviderConnection())->verified('personalized_pod',$id,'printify.connection.read');self::assertNotWPError($v);
  return new ShopBinding(2,'personalized_pod',$id,$v['external_shop_reference'],$v['environment'],$v['review_revision'],new CredentialReference($id),$v['binding_hash'],$v['expires_at']);
 }
 private function context(ShopBinding $s,string $view):array{return ['version'=>'1.0.0','view'=>$view,'session_fingerprint'=>hash('sha256',wp_get_session_token()),'csrf_token'=>wp_create_nonce(NativePanelAccessAdapter::nonceAction($s,$view))];}
 private function port():NativeCorePortInterface {$p=NativeBridge::connect('1.0.0');self::assertInstanceOf(NativeCorePortInterface::class,$p);return $p;}
 private function adapter():NativePortAdapter {return new NativePortAdapter($this->port(),getenv('DF_NATIVE_TEST_SHA'));}
 public function testExactManifestAndFiveActualNativePanelViews():void {
  $s=$this->scope();$p=$this->port();$m=$p->contractManifest();self::assertSame(getenv('DF_NATIVE_TEST_SHA'),$m['core_source_sha']);self::assertTrue(NativePortSchema::valid('manifest',$m));self::assertSame(['PANEL','GET_READ'],$m['capabilities']);
  foreach(['publication','orders','fulfillment','conflicts','unknown']as $view){$r=$this->adapter()->panelAccess($s,get_current_user_id(),$this->context($s,$view));self::assertNotWPError($r);self::assertTrue($r['read_only']);self::assertFalse($r['external_execution_authorized']);}
 }
 public function testActualReadOnlyWorkflowAuthorizationDoesNotSupplySnapshots():void {
  $s=$this->scope();$p=$this->port();$a=get_current_user_id();foreach(['inspect_reviews','inspect_workflows','inspect_fulfillment','inspect_allocations','inspect_recovery']as $action){$r=$p->authorizeWorkflow($s,$a,$action);self::assertNotWPError($r);self::assertTrue($r['external_writes_denied']);}
  $missing=$p->inspectWorkflows($s,$a,10);self::assertWPError($missing);self::assertSame('printify_native_order_source_durability_unavailable',$missing->get_error_code());self::assertWPError($this->adapter()->inspectWorkflows($s,$a,10));
  foreach(['record_order','record_review']as $action)self::assertWPError($p->authorizeWorkflow($s,$a,$action));
  foreach([[$p->reviewQueue($s,$a,10),'trusted_review_snapshot_join_required'],[$p->allocation($s,$a,['order_identity'=>str_repeat('a',64),'order_version'=>1]),'approved_allocation_proof_join_required'],[$p->recovery($s,$a,['action'=>'INSPECT']),'independent_checkpoint_and_drain_required']]as [$r,$reason]){self::assertWPError($r);self::assertSame('printify_native_'.$reason,$r->get_error_code());}
 }
 public function testPanelsFailClosedWithoutNativeReviewQueueDespiteValidPanelAccess():void {
  $s=$this->scope();$a=get_current_user_id();$adapter=$this->adapter();self::assertNotWPError($adapter->panelAccess($s,$a,$this->context($s,'orders')));$panel=new ProtectedPanel($adapter);self::assertWPError($panel->snapshot($s,$a,$this->context($s,'orders')));self::assertStringContainsString('unavailable',$panel->render($s,$a,$this->context($s,'orders')));self::assertWPError((new RecoveryDiagnostics($adapter))->snapshot($s,$a));
 }
 public function testWrongPinStaleBindingWrongShopSessionAndRevocationDeny():void {
  $s=$this->scope();$p=$this->port();$a=get_current_user_id();$ctx=$this->context($s,'orders');self::assertWPError((new NativePortAdapter($p,str_repeat('0',40)))->panelAccess($s,$a,$ctx));
  $stale=new ShopBinding(2,$s->shopKey,$s->integrationId,$s->providerShopId,$s->environment,$s->revision+1,$s->credential,$s->bindingHash,$s->expiresAt);self::assertWPError($p->panelAccess($stale,$a,$this->context($stale,'orders')));
  $foreign=new ShopBinding(3,'standard_pod',$s->integrationId,$s->providerShopId,$s->environment,$s->revision,$s->credential,$s->bindingHash,$s->expiresAt);self::assertWPError($p->panelAccess($foreign,$a,$this->context($foreign,'orders')));
  $bad=$ctx;$bad['csrf_token']='invalid-nonce';self::assertWPError($p->panelAccess($s,$a,$bad));wp_get_current_user()->add_cap('manage_digiforge_orders',false);self::assertWPError($p->panelAccess($s,$a,$ctx));wp_get_current_user()->add_cap('manage_digiforge_orders',true);WP_Session_Tokens::get_instance($a)->destroy(wp_get_session_token());self::assertWPError($p->panelAccess($s,$a,$ctx));
 }
 public function testLockedActualGetCannotReachEvenMockHttp():void {
  $s=$this->scope();$p=$this->port();$i=new ExternalOperationIntent(get_current_user_id(),$s->shopKey,$s->integrationId,'SHOPS_READ',[],str_repeat('a',32));$r=$p->read($i);self::assertWPError($r);self::assertSame('printify_native_external_locked',$r->get_error_code());self::assertWPError($this->adapter()->read($i));
 }
 public function testMissingNativeMutationAndReceiptJoinsRemainDenied():void {
  $p=$this->port();foreach(['exchange','inspect','recordWorkflow','decideReview','recordFulfillment']as $method)self::assertWPError($p->$method([]));$s=$this->scope();self::assertWPError($p->authorizePublication([],[]));$called=false;self::assertWPError($p->withWebhookSecret($s,static function()use(&$called){$called=true;}));self::assertFalse($called);
 }
 public function testActualHeldCandidateJournalIsDurableLocallyButNotMutationAuthority():void {
  $s=$this->scope();$a=get_current_user_id();global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');
  foreach(NativeHeldJournalSchema::statements()as $sql)self::assertNotFalse($wpdb->query($sql));
  $id=hash('sha256',wp_generate_uuid4());$b=['operation_id'=>$id,'payload_fingerprint'=>str_repeat('b',64),'intent_fingerprint'=>str_repeat('c',64),'scope_fingerprint'=>$s->fingerprint(),'approval_reference'=>str_repeat('e',64),'shop_id'=>2,'integration_id'=>$s->integrationId,'credential_reference'=>$s->integrationId,'actor_id'=>$a,'authorization_revision'=>$s->revision,'shop_key'=>$s->shopKey,'provider_shop_id'=>$s->providerShopId,'environment'=>$s->environment,'product_version'=>1,'expected_product_version'=>1];
  try{$j=new NativeHeldJournal();$r=$j->prepare($b,hash('sha256','candidate-'.$id));self::assertNotWPError($r);self::assertFalse($r['record']['approval_verified']);self::assertFalse($r['record']['dispatch_permitted']);$f=$j->transition($id,$s->shopKey,$a,1,'FENCE');self::assertNotWPError($f);self::assertSame('FENCED',$f['record']['state']);self::assertSame($f,(new NativeHeldJournal())->read($id,$s->shopKey,$a));self::assertWPError($j->transition($id,$s->shopKey,$a,2,'FENCE'));$u=$j->transition($id,$s->shopKey,$a,2,'UNKNOWN');self::assertNotWPError($u);self::assertSame('UNKNOWN',$u['record']['state']);self::assertFalse($u['record']['independent_checkpoint_verified']);self::assertWPError($j->transition($id,$s->shopKey,$a,3,'FENCE'));
   $request=['contract_version'=>'1.0.0','action'=>'REGISTER','binding'=>$b,'context'=>['actor_id'=>$a,'logical_reference'=>'SYNTHETIC_ONLY','approval_reference'=>$b['approval_reference'],'authorization_revision'=>$s->revision,'product_version'=>1,'expected_product_version'=>1],'evidence_references'=>[],'duplicate_key'=>str_repeat('f',64),'data'=>[]];foreach(['REGISTER','CLAIM','RECONCILE','RECOVER']as $action){$request['action']=$action;$denial=$this->port()->exchange($request);self::assertWPError($denial);self::assertSame('printify_native_journal_and_independent_checkpoint_required',$denial->get_error_code());}self::assertSame('printify_native_trusted_operation_identity_join_required',$this->port()->inspect($b)->get_error_code());self::assertSame('printify_native_external_locked',$this->port()->authorizePublication($b,[])->get_error_code());
  }finally{foreach(NativeHeldJournalSchema::tables()as $t)$wpdb->query('DROP TABLE IF EXISTS '.$t);}
 }
}
