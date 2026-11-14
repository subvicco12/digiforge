<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForgePrintifyConnector\Preparation\{CoreMutationHost,MutationIntent,MutationFence,VersionedMutationHost,PreparationService,NativePortAdapter,ProviderReceipt,ProviderMutationResponse,WorkflowCoordinator,OrderReadModel,ReviewCoordinator,ShipmentReadModel,ProtectedPanel};
require_once __DIR__.'/../fixtures/printify-connector/stage34/companion-test-bootstrap.php';
require_once __DIR__.'/../fixtures/printify-connector/batch6/SyntheticNativeCore.php';
final class PrintifyCompanionCompletionSprintTest extends TestCase {
 private function intent():MutationIntent{return (require __DIR__.'/../fixtures/printify-connector/batch2/intent.php')();}
 /** @dataProvider registrationStates */
 public function testRegistrationCannotRelabelAnUncertainOrFencedCoreResult(string $state):void {
  $i=$this->intent();$host=$this->registrationHost($state,false);self::assertInstanceOf(WP_Error::class,(new MutationFence($host))->register($i));
 }
 public static function registrationStates():array{return [['UNKNOWN'],['FENCED'],['CANCELLED']];}
 public function testRevocationAfterRegistrationDoesNotReturnPreparedEvidence():void {self::assertInstanceOf(WP_Error::class,(new MutationFence($this->registrationHost('PREPARED',true)))->register($this->intent()));}
 private function registrationHost(string $state,bool $revoke):CoreMutationHost {
  return new class($state,$revoke) implements CoreMutationHost {
   private SyntheticMutationCore $core;private VersionedMutationHost $host;
   public function __construct(private string $state,private bool $revoke){$this->core=new SyntheticMutationCore();$this->host=new VersionedMutationHost($this->core);}
   public function authorize(MutationIntent $i):array|WP_Error{return $this->host->authorize($i);}
   public function register(MutationIntent $i):array|WP_Error{$r=$this->host->register($i);if(is_array($r))$r['state']=$this->state;if($this->revoke)$this->core->fault='revoked';return $r;}
   public function claimOnce(MutationIntent $i,array $a):array|WP_Error{return $this->host->claimOnce($i,$a);}
   public function responseEvidence(MutationIntent $i,string $ref):array|WP_Error{return $this->host->responseEvidence($i,$ref);}
   public function outcome(MutationIntent $i,string $s,array $r):bool{return $this->host->outcome($i,$s,$r);}
  };
 }
 public function testSyntheticFullLifecycleNeverTurnsObservationReviewOrReceiptIntoExecutionAuthority():void {
  require_once dirname(__DIR__,2).'/includes/Research/DesignFactoryContract.php';
  [$scope,$input,$e]=(require __DIR__.'/../fixtures/printify-connector/stage34/product-fixture.php')();
  $scope=new DigiForge\Connector\Printify\ShopBinding(2,'personalized_pod',99001,'99002','test',7,new DigiForge\Connector\Printify\CredentialReference(99001),str_repeat('a',64),2000000000,['synthetic_image']);
  [$bytes,$art]=(require __DIR__.'/../fixtures/printify-connector/stage34/artwork-fixture.php')();$art['integration_id']=99001;$art['provider_shop_id']='99002';
  $core=new SyntheticNativeCore();$core->safetyStop=false;$core->externalLock=false;$port=new NativePortAdapter($core);$host=new VersionedMutationHost($port);$fence=new MutationFence($host);$service=new PreparationService();
  $ctx=static fn(string $ref,int $v):array=>['actor_id'=>1,'logical_reference'=>$ref,'approval_reference'=>str_repeat('d',64),'authorization_revision'=>7,'product_version'=>$v,'expected_product_version'=>$v];
  $upload=$service->upload($scope,$bytes,$art,$ctx('sprint-artwork',0));$uploaded=$this->receipt($upload,$core,$host,$fence);self::assertFalse(ProviderReceipt::validate($upload,$uploaded)['provider_content_verified']);
  // Synthetic core fixtures supply reviewed version/ownership references; no receipt is promoted into authority.
  $input['print_areas'][0]['placeholders'][0]['images'][0]['id']='synthetic_image';$e['owned_uploads'][0]=['image_id'=>'synthetic_image','asset_sha256'=>hash('sha256',$bytes),'upload_receipt_sha256'=>$uploaded['receipt_hash'],'shop_key'=>'personalized_pod','integration_id'=>99001,'provider_shop_id'=>'99002','width_px'=>10,'height_px'=>10,'content_bounds'=>['x'=>3,'y'=>3,'width'=>4,'height'=>4]];$e['print_areas']=[['position'=>'front','width_px'=>10,'height_px'=>10,'safe_zone'=>['x'=>2,'y'=>2,'width'=>6,'height'=>6]]];
  $created=$service->product('PRODUCT_CREATE',$scope,$input,$e,$ctx('sprint-product',0));$receipt=$this->receipt($created,$core,$host,$fence);self::assertSame('synthetic_product',$receipt['body']['id']);
  $e['current_product_id']='synthetic_product';$e['product_version']=$e['expected_product_version']=1;$input['product_id']='synthetic_product';$input['known_variant_ids']=[99005];$input['variants'][0]['price']=1400;
  $updated=$service->product('PRODUCT_UPDATE',$scope,$input,$e,$ctx('sprint-product',1));$this->receipt($updated,$core,$host,$fence);
  [, $publish,$pe]=(require __DIR__.'/../fixtures/printify-connector/batch4/fixture.php')();$publication=$service->publish($scope,$publish,$pe,$ctx('sprint-product',1));$published=$this->receipt($publication,$core,$host,$fence);self::assertSame('PUBLISH_PENDING',ProviderReceipt::validate($publication,$published)['state']);
  [,,$shipments,$alloc,$fc,$raw,$lineage]=(require __DIR__.'/../fixtures/printify-connector/batch5/fixture.php')();$order=OrderReadModel::fromProvider($scope,$raw,$lineage,str_repeat('b',64));$workflow=new WorkflowCoordinator($port);self::assertSame('RECORDED',$workflow->ingestOrder($scope,1,$order)['disposition']);self::assertSame('DUPLICATE',$workflow->ingestOrder($scope,1,$order)['disposition']);
  [,,,,,,$event,$secret]=(require __DIR__.'/../fixtures/printify-connector/batch4/fixture.php')();$payload=json_encode($event,JSON_THROW_ON_ERROR);$signature='sha256='.hash_hmac('sha256',$payload,$secret);self::assertSame('RECORDED',$workflow->webhook($scope,1,$payload,$signature)['disposition']);self::assertSame('DUPLICATE',$workflow->webhook($scope,1,$payload,$signature)['disposition']);
  $review=new ReviewCoordinator($port);$partial=ShipmentReadModel::fromProvider($scope,$order,$shipments,$alloc,$fc);self::assertSame('PARTIAL_TRACKING_OBSERVED',$partial->jsonSerialize()['state']);self::assertSame('RECORDED',$review->observeFulfillment($scope,1,$partial)['disposition']);self::assertSame('DUPLICATE',$review->observeFulfillment($scope,1,$partial)['disposition']);
  $shipments[]=['carrier'=>'usps','number'=>'TRACK456','line_item_ids'=>['synthetic_line_item']];$alloc[]=['carrier'=>'usps','number'=>'TRACK456','items'=>[['line_item_id'=>'synthetic_line_item','quantity'=>1]]];$fc['observed_at']++;$complete=ShipmentReadModel::fromProvider($scope,$order,$shipments,$alloc,$fc);self::assertSame('COMPLETE_TRACKING_OBSERVED',$complete->jsonSerialize()['state']);self::assertSame('RECORDED',$review->observeFulfillment($scope,1,$complete)['disposition']);
  $core->operations->kind='PUBLICATION';$panel=new ProtectedPanel($port);self::assertStringContainsString('Read only',$panel->render($scope,1,$core->context('publication')));$export=$workflow->export($scope,1);self::assertIsString($export);self::assertStringNotContainsString('Private Street',$export);self::assertStringNotContainsString('synthetic@example.invalid',$export);
  $core->emergency();self::assertInstanceOf(WP_Error::class,$fence->reserve($publication));$core->fault='revoked';self::assertInstanceOf(WP_Error::class,$panel->snapshot($scope,1,$core->context('publication')));
 }
 private function receipt(MutationIntent $i,SyntheticNativeCore $core,VersionedMutationHost $host,MutationFence $fence):array {
  self::assertIsArray($fence->register($i));self::assertIsArray($fence->reserve($i));self::assertInstanceOf(WP_Error::class,$fence->reserve($i));$r=$core->mutation->receipt($i);$observation=ProviderMutationResponse::observe($i,$r['body'],200);self::assertIsArray($observation);self::assertFalse($observation['trusted_receipt']);self::assertFalse($observation['external_execution_authorized']);self::assertSame('METADATA_RECONCILED',$host->acceptReceipt($i,$r)['state']);self::assertSame('METADATA_RECONCILED',$host->acceptReceipt($i,$r)['state']);return $r;
 }
}
