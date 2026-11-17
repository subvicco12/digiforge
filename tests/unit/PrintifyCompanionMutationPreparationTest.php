<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForgePrintifyConnector\Preparation\{PreparationService,MutationIntent,MutationFence,VersionedMutationHost,ProviderReceipt,ProviderMutationResponse,MutationCoreInterface};
require_once __DIR__.'/../fixtures/printify-connector/stage34/companion-test-bootstrap.php';
require_once __DIR__.'/../fixtures/printify-connector/batch2/SyntheticMutationCore.php';
final class PrintifyCompanionMutationPreparationTest extends TestCase {
 private function intent(string $operation='IMAGE_UPLOAD'):MutationIntent {
  if($operation==='IMAGE_UPLOAD')return (require __DIR__.'/../fixtures/printify-connector/batch2/intent.php')();
  if($operation==='PRODUCT_PUBLISH'){[$s,$in,$e,$ctx]=(require __DIR__.'/../fixtures/printify-connector/batch4/fixture.php')();return (new PreparationService())->publish($s,$in,$e,$ctx);}
  [$s,$in,$e]=(require __DIR__.'/../fixtures/printify-connector/stage34/product-fixture.php')();
  if($operation==='PRODUCT_UPDATE'){$in['product_id']='fixture_product';$in['known_variant_ids']=[99005];$e['current_product_id']='fixture_product';$e['product_version']=$e['expected_product_version']=1;}
  return (new PreparationService())->product($operation,$s,$in,$e,['actor_id'=>1,'logical_reference'=>'core-product-1','approval_reference'=>$e['approval_receipt_sha256'],'authorization_revision'=>7,'product_version'=>$e['product_version'],'expected_product_version'=>$e['expected_product_version']]);
 }
 public function testRevocationDuringReceiptReadCannotReturnProtectedEvidence():void {
  $i=$this->intent();$c=new SyntheticMutationCore();$base=new VersionedMutationHost($c);$f=new MutationFence($base);self::assertIsArray($f->register($i));self::assertIsArray($f->reserve($i));$r=$c->receipt($i);$c->receipts[$r['receipt_hash']]=$r;
  $revoking=new class($c) implements MutationCoreInterface {
   public function __construct(private SyntheticMutationCore $core){}public function version():string{return '1.0.0';}
   public function exchange(array $q):array|WP_Error{$r=$this->core->exchange($q);if($q['action']==='RECEIPT_READ')$this->core->fault='revoked';return $r;}
  };
  self::assertInstanceOf(WP_Error::class,(new VersionedMutationHost($revoking))->responseEvidence($i,$r['receipt_hash']));
 }
 public static function operations():array{return array_map(static fn($v)=>[$v],['IMAGE_UPLOAD','PRODUCT_CREATE','PRODUCT_UPDATE','PRODUCT_PUBLISH']);}
 /** @dataProvider operations */
 public function testProviderObservationPreservesIdentityButNeverAttestsOrDispatches(string $operation):void {
  self::assertTrue(class_exists(ProviderMutationResponse::class),'Pure provider mutation response projection is required.');
  $i=$this->intent($operation);$c=new SyntheticMutationCore();$raw=$c->receipt($i)['body'];
  if($operation!=='PRODUCT_PUBLISH')$raw+=['preview_url'=>'https://example.invalid/private','email'=>'private@example.invalid','access_token'=>'SYNTHETIC-DO-NOT-EXPORT'];
  $o=ProviderMutationResponse::observe($i,$raw,200);self::assertIsArray($o);self::assertSame($i->operationId,$o['operation_id']);self::assertSame($i->context['authorization_revision'],$o['authorization_revision']);self::assertSame($i->context['product_version'],$o['product_version']);self::assertFalse($o['trusted_receipt']);self::assertFalse($o['retry_permitted']);self::assertFalse($o['external_execution_authorized']);self::assertArrayNotHasKey('receipt_hash',$o);self::assertStringNotContainsString('private',json_encode($o));self::assertStringNotContainsString('SYNTHETIC-DO-NOT-EXPORT',json_encode($o));
  self::assertSame($operation==='PRODUCT_PUBLISH'?'PUBLISH_PENDING':($operation==='IMAGE_UPLOAD'?'METADATA_OBSERVED_CONTENT_UNVERIFIED':'PRODUCT_METADATA_OBSERVED_UNCERTIFIED'),$o['metadata']['state']);
  $f=new MutationFence();self::assertInstanceOf(WP_Error::class,$f->register($i));self::assertSame('UNKNOWN',$f->reconcile($i,str_repeat('a',64))['state']);
 }
 public static function faults():array {$out=[];foreach(['IMAGE_UPLOAD','PRODUCT_CREATE','PRODUCT_UPDATE','PRODUCT_PUBLISH']as $op)foreach([0,401,403,429,500]as $status)$out[$op.'_'.$status]=[$op,$status];return $out;}
 /** @dataProvider faults */
 public function testUncertainProviderResponsesNeverRestoreWriteAuthority(string $op,int $status):void {
  self::assertTrue(class_exists(ProviderMutationResponse::class));$i=$this->intent($op);$r=ProviderMutationResponse::observe($i,['error'=>'private-provider-secret'],$status);self::assertIsArray($r);self::assertSame('UNKNOWN',$r['kind']);self::assertSame([],$r['body']);self::assertFalse($r['retry_permitted']);self::assertStringNotContainsString('private-provider-secret',json_encode($r));
 }
 /** @dataProvider operations */
 public function testReceiptIdentityRevisionAndHashTamperingAreRejected(string $op):void {
  $i=$this->intent($op);$c=new SyntheticMutationCore();$r=$c->receipt($i);
  foreach(['shop_key','provider_shop_id','credential_reference','approval_reference','authorization_revision','product_version','payload_fingerprint','operation_id']as $key){$bad=$r;$bad[$key]=is_int($bad[$key])?$bad[$key]+1:'wrong';$bad['receipt_hash']=ProviderReceipt::hash($bad);try{ProviderReceipt::validate($i,$bad);self::fail('Altered identity/revision must deny: '.$key);}catch(InvalidArgumentException){self::assertTrue(true);}}
  $r['receipt_hash']=str_repeat('0',64);$this->expectException(InvalidArgumentException::class);ProviderReceipt::validate($i,$r);
 }
 /** @dataProvider operations */
 public function testAllPreparedOperationsRemainOneShotAcrossUnknownAndConflictingReceipts(string $op):void {
  $i=$this->intent($op);$core=new SyntheticMutationCore();$host=new VersionedMutationHost($core);$f=new MutationFence($host);
  self::assertIsArray($f->register($i));self::assertInstanceOf(WP_Error::class,$f->register($i));self::assertIsArray($f->reserve($i));self::assertInstanceOf(WP_Error::class,$f->reserve($i));
  $u=$core->receipt($i);$u['kind']='UNKNOWN';$u['http_status']=0;$u['body']=[];$u['receipt_hash']=ProviderReceipt::hash($u);self::assertSame('UNKNOWN',$host->acceptReceipt($i,$u)['state']);self::assertInstanceOf(WP_Error::class,$f->reserve($i));
  $r=$core->receipt($i);self::assertInstanceOf(WP_Error::class,$host->acceptReceipt($i,$r));$r['sequence']=2;$r['event_id']=hash('sha256','synthetic-terminal-'.$op);$r['receipt_hash']=ProviderReceipt::hash($r);self::assertSame('METADATA_RECONCILED',$host->acceptReceipt($i,$r)['state']);self::assertSame('METADATA_RECONCILED',$host->acceptReceipt($i,$r)['state']);
  $r['sequence']=3;$r['receipt_hash']=ProviderReceipt::hash($r);self::assertInstanceOf(WP_Error::class,$host->acceptReceipt($i,$r));self::assertSame(2,$core->receiptCount);self::assertInstanceOf(WP_Error::class,$f->reserve($i));
 }
 public function testProjectionRejectsPublishedDraftBeforeDiscardingProviderFields():void {
  self::assertTrue(class_exists(ProviderMutationResponse::class));$i=$this->intent('PRODUCT_CREATE');$raw=(new SyntheticMutationCore())->receipt($i)['body'];$raw['published']=true;self::assertInstanceOf(WP_Error::class,ProviderMutationResponse::observe($i,$raw,200));
 }
 public function testExplicitForeignUploadShopAndOversizedBodyAreRejected():void {
  $i=$this->intent();$raw=(new SyntheticMutationCore())->receipt($i)['body'];$raw['shop_id']=999;self::assertInstanceOf(WP_Error::class,ProviderMutationResponse::observe($i,$raw,200));
  unset($raw['shop_id']);$raw['unneeded']=str_repeat('x',1048577);self::assertInstanceOf(WP_Error::class,ProviderMutationResponse::observe($i,$raw,200));
 }
 public function testNonJsonProviderObjectsCannotExecuteDuringProjection():void {
  $i=$this->intent();$raw=(new SyntheticMutationCore())->receipt($i)['body'];$calls=0;$raw['unneeded']=new class($calls) implements JsonSerializable{public function __construct(private int &$calls){}public function jsonSerialize():mixed{++$this->calls;return 'synthetic';}};
  self::assertInstanceOf(WP_Error::class,ProviderMutationResponse::observe($i,$raw,200));self::assertSame(0,$calls);
 }
 public function testOptionalUploadMetadataMustNotContradictKnownAsset():void {
  $i=$this->intent();$raw=(new SyntheticMutationCore())->receipt($i)['body'];$raw['size']=1;self::assertInstanceOf(WP_Error::class,ProviderMutationResponse::observe($i,$raw,200));
  unset($raw['size']);$raw['mime_type']='image/jpeg';self::assertInstanceOf(WP_Error::class,ProviderMutationResponse::observe($i,$raw,200));
 }
 public function testMalformedUploadAndConflictingUpdateRemainUnknown():void {
  self::assertTrue(class_exists(ProviderMutationResponse::class));foreach(['IMAGE_UPLOAD','PRODUCT_UPDATE']as $op){$i=$this->intent($op);$raw=(new SyntheticMutationCore())->receipt($i)['body'];if($op==='IMAGE_UPLOAD')$raw['width']=0;else $raw['id']='another_product';self::assertInstanceOf(WP_Error::class,ProviderMutationResponse::observe($i,$raw,200));}
 }
}
