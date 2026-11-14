<?php
declare(strict_types=1);
/** Actual frozen core denials/partial primitives; never synthetic mutation success. */
use DigiForge\Connector\Printify\{NativeBridge,NativePortContract,ShopBinding,CredentialReference};
use DigiForgePrintifyConnector\Preparation\{NativePortAdapter,VersionedMutationHost};
require_once DIGIFORGE_PATH.'tests/wordpress/fixtures/PrintifyApiApprovalFixture.php';
final class NativeMutationWordPressAcceptance extends WP_UnitTestCase {
 private PrintifyApiApprovalFixture $fixture;private ShopBinding $scope;private int $http=0;private $guard;
 protected function setUp():void{parent::setUp();$this->fixture=new PrintifyApiApprovalFixture();$this->fixture->boot();$v=PrintifyApiApprovalFixture::checked((new DigiForge\POD\ScopedProviderConnection())->verified('personalized_pod',$this->fixture->integration,'printify.connection.read'));$this->scope=new ShopBinding(2,'personalized_pod',$this->fixture->integration,$v['external_shop_reference'],$v['environment'],$v['review_revision'],new CredentialReference($this->fixture->integration),$v['binding_hash'],$v['expires_at']);$this->guard=function(){++$this->http;throw new RuntimeException('HTTP prohibited');};add_filter('pre_http_request',$this->guard,PHP_INT_MAX);}
 protected function tearDown():void{remove_filter('pre_http_request',$this->guard,PHP_INT_MAX);self::assertSame(0,$this->http);$this->fixture->restore();parent::tearDown();}
 private function request(string $action):array{$b=['operation_id'=>str_repeat('a',64),'payload_fingerprint'=>str_repeat('b',64),'intent_fingerprint'=>str_repeat('c',64),'scope_fingerprint'=>$this->scope->fingerprint(),'shop_id'=>2,'shop_key'=>'personalized_pod','integration_id'=>$this->scope->integrationId,'credential_reference'=>$this->scope->integrationId,'provider_shop_id'=>$this->scope->providerShopId,'environment'=>'test','actor_id'=>1,'authorization_revision'=>$this->scope->revision,'approval_reference'=>str_repeat('d',64),'product_version'=>1,'expected_product_version'=>1];return ['contract_version'=>'1.0.0','action'=>$action,'binding'=>$b,'context'=>['actor_id'=>1,'logical_reference'=>'synthetic-native-mutation','approval_reference'=>$b['approval_reference'],'authorization_revision'=>$b['authorization_revision'],'product_version'=>1,'expected_product_version'=>1],'evidence_references'=>[],'duplicate_key'=>str_repeat('e',64),'data'=>[]];}
 public function testActualCoreRequestBuilderSupportsInertCompanionUploadDraftAndPublicationContracts():void {
  self::assertStringStartsWith(DIGIFORGE_PATH,(new ReflectionClass(DigiForge\POD\PrintifyProductRequest::class))->getFileName());
  $service=new DigiForgePrintifyConnector\Preparation\PreparationService();
  [$bytes,$e,$scope]=(require '/companionrepo/tests/fixtures/printify-connector/stage34/artwork-fixture.php')();
  $i=$service->upload($scope,$bytes,$e,['actor_id'=>1,'logical_reference'=>'native-fixture-upload','approval_reference'=>str_repeat('d',64),'authorization_revision'=>1,'product_version'=>1,'expected_product_version'=>0]);
  $a=$i->prepared()['asset'];$body=['id'=>'native_fixture_image','file_name'=>$a['sha256'].'.png','width'=>10,'height'=>10];$items=[[$i,$body,'POST','/v1/uploads/images.json']];
  foreach(['PRODUCT_CREATE','PRODUCT_UPDATE']as $op){[$scope,$input,$e,$body]=(require '/companionrepo/tests/fixtures/printify-connector/stage34/product-fixture.php')();if($op==='PRODUCT_UPDATE'){$input['product_id']='fixture_product';$input['known_variant_ids']=[99005];$e['current_product_id']='fixture_product';$e['product_version']=$e['expected_product_version']=1;}
   $i=$service->product($op,$scope,$input,$e,['actor_id'=>1,'logical_reference'=>'native-fixture-product','approval_reference'=>$e['approval_receipt_sha256'],'authorization_revision'=>7,'product_version'=>$e['product_version'],'expected_product_version'=>$e['expected_product_version']]);$items[]=[$i,$body,$op==='PRODUCT_CREATE'?'POST':'PUT',$op==='PRODUCT_CREATE'?'/v1/shops/99002/products.json':'/v1/shops/99002/products/fixture_product.json'];
  }
  [$scope,$input,$e,$ctx]=(require '/companionrepo/tests/fixtures/printify-connector/batch4/fixture.php')();$items[]=[$service->publish($scope,$input,$e,$ctx),[],'POST','/v1/shops/99002/products/synthetic_product/publish.json'];
  foreach($items as [$i,$body,$method,$path]){$p=$i->prepared();$wire=$p['core_request']??($p['request']??$p);self::assertSame($method,$wire['method']);self::assertSame($path,$wire['endpoint']??$wire['path']);$r=DigiForgePrintifyConnector\Preparation\ProviderMutationResponse::observe($i,$body,200);self::assertNotWPError($r);self::assertFalse($r['trusted_receipt']);self::assertFalse($r['network_request_permitted']);self::assertWPError((new VersionedMutationHost(new NativePortAdapter(NativeBridge::connect('1.0.0'))))->register($i));}
 }
 public function testAllCanonicalActionsRemainDeniedDespiteValidScopeAndNativeOwner():void {
  $core=NativeBridge::connect('1.0.0');$adapter=new NativePortAdapter($core);self::assertSame(getenv('DF_NATIVE_TEST_SHA'),$core->contractManifest()['core_source_sha']);self::assertSame(['PANEL','GET_READ'],$core->contractManifest()['capabilities']);
  foreach(['AUTHORIZE','REGISTER','CLAIM','RECEIPT_READ','RECONCILE','OUTCOME','CANCEL','RECOVER']as $action){$q=$this->request($action);self::assertTrue(NativePortContract::valid('mutation_request',$q));$r=$core->exchange($q);self::assertWPError($r);self::assertSame('printify_native_journal_and_independent_checkpoint_required',$r->get_error_code());self::assertWPError($adapter->exchange($q));}
  self::assertWPError($core->inspect($this->request('AUTHORIZE')['binding']));self::assertWPError($core->authorizePublication($this->request('AUTHORIZE')['binding'],[]));
 }
 public function testStopRevocationAndCrossShopNeverTurnMissingMutationPortsIntoAuthority():void {
  $core=NativeBridge::connect('1.0.0');$q=$this->request('REGISTER');$this->fixture->setting('stop_all',true);self::assertWPError($core->exchange($q));self::assertWPError($core->authorizePublication($q['binding'],[]));
  $q['binding']['shop_key']='standard_pod';self::assertWPError($core->exchange($q));$q=$this->request('REGISTER');wp_get_current_user()->add_cap('manage_digiforge_pod',false);self::assertWPError($core->exchange($q));
 }
 public function testPartialReadAuthorityCannotSubstituteForSnapshotPublicationAllocationOrRecovery():void {
  $core=NativeBridge::connect('1.0.0');$adapter=new NativePortAdapter($core);self::assertNotWPError($core->authorizeWorkflow($this->scope,1,'inspect_workflows'));self::assertWPError($adapter->authorizeWorkflow($this->scope,1,'inspect_workflows'));self::assertWPError($core->reviewQueue($this->scope,1,10));self::assertWPError($core->recordWorkflow([]));self::assertWPError($core->decideReview([]));self::assertWPError($core->recordFulfillment([]));self::assertWPError($core->withWebhookSecret($this->scope,static function(){self::fail('Secret callback must never run');}));
  $a=['order_identity'=>str_repeat('a',64),'order_version'=>1];self::assertTrue(NativePortContract::valid('allocation_request',$a));self::assertWPError($core->allocation($this->scope,1,$a));$r=['action'=>'INSPECT'];self::assertTrue(NativePortContract::valid('recovery_request',$r));self::assertWPError($core->recovery($this->scope,1,$r));
 }
}
