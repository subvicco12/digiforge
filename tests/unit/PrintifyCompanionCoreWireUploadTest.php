<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForgePrintifyConnector\Preparation\{PreparationService,MutationIntent,UploadContract,ProviderMutationResponse,ArtworkContract,CoreUploadWireContract,ProviderReceipt,VersionedMutationHost};
require_once __DIR__.'/../fixtures/printify-connector/stage34/companion-test-bootstrap.php';
require_once __DIR__.'/../fixtures/printify-connector/batch2/SyntheticMutationCore.php';
final class PrintifyCompanionCoreWireUploadTest extends TestCase
{
 private function fixture():array {
  [$bytes,$evidence,$scope]=(require __DIR__.'/../fixtures/printify-connector/stage34/artwork-fixture.php')();
  $descriptor=json_decode(file_get_contents(__DIR__.'/../fixtures/printify-connector/sprint/core-upload-wire.json'),true,32,JSON_THROW_ON_ERROR);
  $context=['actor_id'=>1,'logical_reference'=>'core-wire-fixture','approval_reference'=>str_repeat('c',64),'authorization_revision'=>1,'product_version'=>0,'expected_product_version'=>0];
  return [$scope,$bytes,$evidence,$descriptor,$context];
 }
 public function testNewCoreWirePreparationMatchesLiteralCoreFingerprintWithoutRewritingPreviousIntent():void {
  self::assertTrue(method_exists(PreparationService::class,'uploadFromCoreDescriptor'),'Additive core wire preparation required.');
  [$s,$bytes,$e,$d,$c]=$this->fixture();$service=new PreparationService();$old=$service->upload($s,$bytes,$e,$c);$oldSnapshot=$old->prepared();
  $new=$service->uploadFromCoreDescriptor($s,$bytes,$e,$d,$c);
  self::assertSame('b1cac58fcb6cbdf7c21d7bdfa1c5a899c84ae53c8296e5d332981c6cf416a592',$new->payloadFingerprint);
  self::assertNotSame($old->payloadFingerprint,$new->payloadFingerprint);self::assertNotSame($old->operationId,$new->operationId);
  self::assertSame($old->duplicateKey,$new->duplicateKey);self::assertSame($oldSnapshot,$old->prepared());
  self::assertSame(hash('sha256',$bytes).'.png',$old->prepared()['body']['file_name']);self::assertSame('shop2-ffffffffffffffff.png',$new->prepared()['body']['file_name']);
  self::assertFalse($new->prepared()['network_request_permitted']);self::assertFalse($new->prepared()['source_authenticated']);self::assertTrue($new->prepared()['current_core_verification_required']);
  self::assertFalse($new->jsonSerialize()['external_execution_authorized']);self::assertTrue($new->jsonSerialize()['core_evidence_verification_required']);
 }
 /** @dataProvider invalidDescriptors */
 public function testInvalidIdentityOrWireCannotBecomePreparedEvenWithRehashedDescriptor(string $field,mixed $value):void {
  self::assertTrue(method_exists(PreparationService::class,'uploadFromCoreDescriptor'));
  [$s,$bytes,$e,$d,$c]=$this->fixture();$d[$field]=$value;unset($d['descriptor_hash']);$d['descriptor_hash']=MutationIntent::hash($d);
  $this->expectException(InvalidArgumentException::class);(new PreparationService())->uploadFromCoreDescriptor($s,$bytes,$e,$d,$c);
 }
 public static function invalidDescriptors():array{return [
  ['schema','OTHER'],['version','2.0.0'],['scope_fingerprint',str_repeat('0',64)],['actor_id',2],['actor_id','1'],['asset_sha256',str_repeat('0',64)],
  ['file_name','renamed.png'],['file_name','../escape.png'],['method','GET'],['endpoint','https://evil.invalid/upload'],['payload_fingerprint',str_repeat('0',64)],['source_evidence_hash','unknown'],['provider_credentials','FORBIDDEN_SYNTHETIC_SENTINEL'],
 ];}
 public function testDescriptorObjectsAreRejectedBeforeSerializationCallbacks():void {
  self::assertTrue(method_exists(PreparationService::class,'uploadFromCoreDescriptor'));
  [$s,$bytes,$e,$d,$c]=$this->fixture();$d['extra']=new class implements JsonSerializable {public function jsonSerialize():mixed{throw new LogicException('Callback must never execute');}};
  $this->expectException(InvalidArgumentException::class);(new PreparationService())->uploadFromCoreDescriptor($s,$bytes,$e,$d,$c);
 }
 public function testCurrentSourceClaimsStillDoNotGrantAuthorityAndMatchingReceiptOnlyObservesMetadata():void {
  self::assertTrue(method_exists(PreparationService::class,'uploadFromCoreDescriptor'));
  [$s,$bytes,$e,$d,$c]=$this->fixture();$intent=(new PreparationService())->uploadFromCoreDescriptor($s,$bytes,$e,$d,$c);$p=$intent->prepared();
  $r=UploadContract::reconcile($p,['id'=>'synthetic_image','file_name'=>'shop2-ffffffffffffffff.png','width'=>10,'height'=>10]);
  self::assertSame('METADATA_OBSERVED_CONTENT_UNVERIFIED',$r['state']);self::assertFalse($r['ownership_certified']);self::assertFalse($r['provider_content_verified']);
  self::assertTrue($r['current_core_verification_required']);self::assertFalse($r['source_authenticated']);
  $unknown=UploadContract::reconcile($p,[],0);self::assertSame('UNKNOWN',$unknown['state']);self::assertFalse($unknown['retry_permitted']);
  $this->expectException(InvalidArgumentException::class);UploadContract::reconcile($p,['id'=>'synthetic_image','file_name'=>hash('sha256',$bytes).'.png','width'=>10,'height'=>10]);
 }
 public function testTamperedPreparedBodyCannotReuseTheCoreDescriptor():void {
  self::assertTrue(method_exists(PreparationService::class,'uploadFromCoreDescriptor'));
  [$s,$bytes,$e,$d,$c]=$this->fixture();$p=(new PreparationService())->uploadFromCoreDescriptor($s,$bytes,$e,$d,$c)->prepared();$p['body']['file_name']=hash('sha256',$bytes).'.png';
  $this->expectException(InvalidArgumentException::class);new MutationIntent('IMAGE_UPLOAD',$s,$p,$c);
 }
 public function testMissingRightsEvidenceCannotBeReplacedByDescriptorHash():void {
  self::assertTrue(method_exists(PreparationService::class,'uploadFromCoreDescriptor'));
  [$s,$bytes,$e,$d,$c]=$this->fixture();unset($e['rights_receipt_sha256']);
  $this->expectException(InvalidArgumentException::class);(new PreparationService())->uploadFromCoreDescriptor($s,$bytes,$e,$d,$c);
 }
 public function testConsistentlyRehashedFilenameOutsideCoreRecipeIsStillDenied():void {
  [$s,$bytes,$e,$d,$c]=$this->fixture();$d=json_decode(file_get_contents(__DIR__.'/../fixtures/printify-connector/sprint/core-upload-wire-wrong-name.json'),true,32,JSON_THROW_ON_ERROR);
  $this->expectException(InvalidArgumentException::class);(new PreparationService())->uploadFromCoreDescriptor($s,$bytes,$e,$d,$c);
 }
 public function testPhysicalShopThreeCannotUseAnOtherwiseMatchingDescriptor():void {
  [$s,$bytes,$e,$d,$c]=$this->fixture();
  $s=new DigiForge\Connector\Printify\ShopBinding(3,'standard_pod',$s->integrationId,$s->providerShopId,$s->environment,$s->revision,$s->credential,$s->bindingHash,$s->expiresAt);$e['shop_key']='standard_pod';$asset=ArtworkContract::inspect($bytes,$e,$s);
  $d['scope_fingerprint']=$s->fingerprint();unset($d['descriptor_hash']);$d['descriptor_hash']=MutationIntent::hash($d);
  $this->expectException(InvalidArgumentException::class);UploadContract::prepareFromCoreDescriptor($s,$asset,$bytes,$d,1);
 }
 public function testProviderObservationRetainsFalseTrustAndRejectsConflictingFilename():void {
  [$s,$bytes,$e,$d,$c]=$this->fixture();$i=(new PreparationService())->uploadFromCoreDescriptor($s,$bytes,$e,$d,$c);
  $body=['id'=>'synthetic_image','file_name'=>'shop2-ffffffffffffffff.png','width'=>10,'height'=>10];
  $r=ProviderMutationResponse::observe($i,$body,200);self::assertIsArray($r);self::assertFalse($r['trusted_receipt']);self::assertFalse($r['external_execution_authorized']);self::assertFalse($r['metadata']['source_authenticated']);self::assertTrue($r['metadata']['current_core_verification_required']);
  $body['file_name']='changed.png';self::assertInstanceOf(WP_Error::class,ProviderMutationResponse::observe($i,$body,200));
 }
 /** @dataProvider descriptorFields */
 public function testAllowedFieldObjectsCannotInvokeSerializationCallbacks(string $field):void {
  [$s,$bytes,$e,$d,$c]=$this->fixture();$d[$field]=new class implements JsonSerializable {public function jsonSerialize():mixed{throw new LogicException('Callback must not execute');}};
  $this->expectException(InvalidArgumentException::class);(new PreparationService())->uploadFromCoreDescriptor($s,$bytes,$e,$d,$c);
 }
 public static function descriptorFields():array{return array_map(static fn(string $field):array=>[$field],['schema','version','scope_fingerprint','actor_id','source_evidence_hash','plan_hash','package_hash','asset_sha256','file_name','method','endpoint','payload_fingerprint','descriptor_hash']);}
 public function testMissingFieldOrInvalidSelfHashCannotBecomePrepared():void {
  [$s,$bytes,$e,$d,$c]=$this->fixture();$missing=$d;unset($missing['plan_hash'],$missing['descriptor_hash']);$missing['descriptor_hash']=MutationIntent::hash($missing);
  foreach([$missing,array_replace($d,['descriptor_hash'=>str_repeat('0',64)]),array_replace($d,['source_evidence_hash'=>str_repeat('a',4097)])]as $bad){try{(new PreparationService())->uploadFromCoreDescriptor($s,$bytes,$e,$bad,$c);self::fail('Malformed descriptor was accepted');}catch(InvalidArgumentException){self::assertTrue(true);}}
 }
 public function testReconstructionCannotChangeTheDescriptorActorOrGrantTrust():void {
  [$s,$bytes,$e,$d,$c]=$this->fixture();$p=(new PreparationService())->uploadFromCoreDescriptor($s,$bytes,$e,$d,$c)->prepared();$other=$c;$other['actor_id']=2;
  try{new MutationIntent('IMAGE_UPLOAD',$s,$p,$other);self::fail('Changed actor accepted');}catch(InvalidArgumentException){self::assertTrue(true);}
  $p['source_authenticated']=true;$this->expectException(InvalidArgumentException::class);new MutationIntent('IMAGE_UPLOAD',$s,$p,$c);
 }
 public function testOversizedCoherentWireIsDeniedByDescriptorBoundary():void {
  [$s,,$e,$d]=$this->fixture();$bytes=str_repeat("\0",2097153);$contents=base64_encode($bytes);$d['asset_sha256']=hash('sha256',$bytes);
  $d['payload_fingerprint']=MutationIntent::hash(['method'=>'POST','endpoint'=>$d['endpoint'],'body'=>['file_name'=>$d['file_name'],'contents'=>$contents]]);unset($d['descriptor_hash']);$d['descriptor_hash']=MutationIntent::hash($d);
  $this->expectException(InvalidArgumentException::class);CoreUploadWireContract::validate($d,$s->fingerprint(),1,$d['asset_sha256'],$contents);
 }
 public function testCoherentAlternatePackageCannotReuseOldAuthorityOrReceipt():void {
  [$s,$bytes,$e,$d,$c]=$this->fixture();$service=new PreparationService();$old=$service->uploadFromCoreDescriptor($s,$bytes,$e,$d,$c);
  $d['package_hash']=str_repeat('a',64);$d['file_name']='shop2-aaaaaaaaaaaaaaaa.png';$d['payload_fingerprint']=MutationIntent::hash(['method'=>'POST','endpoint'=>$d['endpoint'],'body'=>['file_name'=>$d['file_name'],'contents'=>base64_encode($bytes)]]);unset($d['descriptor_hash']);$d['descriptor_hash']=MutationIntent::hash($d);
  $new=$service->uploadFromCoreDescriptor($s,$bytes,$e,$d,$c);self::assertSame($old->context['approval_reference'],$new->context['approval_reference']);self::assertSame($old->duplicateKey,$new->duplicateKey);
  self::assertNotSame($old->payloadFingerprint,$new->payloadFingerprint);self::assertNotSame($old->operationId,$new->operationId);self::assertNotSame($old->intentFingerprint,$new->intentFingerprint);self::assertFalse($new->prepared()['source_authenticated']);
  $core=new SyntheticMutationCore();$host=new VersionedMutationHost($core);$oldAuthority=$host->authorize($old);self::assertIsArray($oldAuthority);self::assertIsArray($host->register($new));self::assertInstanceOf(WP_Error::class,$host->claimOnce($new,$oldAuthority));
  $this->expectException(InvalidArgumentException::class);ProviderReceipt::validate($new,$core->receipt($old));
 }
 public function testMatchingReceiptSupportsNewWireButRehashedLegacyFilenameIsRejected():void {
  [$s,$bytes,$e,$d,$c]=$this->fixture();$i=(new PreparationService())->uploadFromCoreDescriptor($s,$bytes,$e,$d,$c);$core=new SyntheticMutationCore();$r=$core->receipt($i);
  // Fixture receipt follows the exact new request; this is synthetic metadata, not provider attestation.
  $r['body']['file_name']='shop2-ffffffffffffffff.png';$r['receipt_hash']=ProviderReceipt::hash($r);
  $m=ProviderReceipt::validate($i,$r);self::assertFalse($m['provider_content_verified']);self::assertFalse($m['ownership_certified']);self::assertFalse($m['source_authenticated']);
  $r['body']['file_name']=hash('sha256',$bytes).'.png';$r['receipt_hash']=ProviderReceipt::hash($r);
  $this->expectException(InvalidArgumentException::class);ProviderReceipt::validate($i,$r);
 }
 public function testPreparedTrustFlagObjectsCannotExecuteWhileReconstructingAnIntent():void {
  [$s,$bytes,$e,$d,$c]=$this->fixture();$p=(new PreparationService())->uploadFromCoreDescriptor($s,$bytes,$e,$d,$c)->prepared();
  $p['source_authenticated']=new class implements JsonSerializable {public function jsonSerialize():mixed{throw new LogicException('Prepared callback must not execute');}};
  $this->expectException(InvalidArgumentException::class);new MutationIntent('IMAGE_UPLOAD',$s,$p,$c);
 }
 public function testDescriptorMapOrderCannotChangeImmutableIntentIdentity():void {
  [$s,$bytes,$e,$d,$c]=$this->fixture();$service=new PreparationService();$a=$service->uploadFromCoreDescriptor($s,$bytes,$e,$d,$c);$b=$service->uploadFromCoreDescriptor($s,$bytes,$e,array_reverse($d,true),$c);
  self::assertSame($a->prepared()['request_fingerprint'],$b->prepared()['request_fingerprint']);self::assertSame($a->payloadFingerprint,$b->payloadFingerprint);self::assertSame($a->intentFingerprint,$b->intentFingerprint);
 }
 public function testExternalBodyReferenceCannotChangeAnAlreadyConstructedIntent():void {
  [$s,$bytes,$e,$d,$c]=$this->fixture();$p=(new PreparationService())->uploadFromCoreDescriptor($s,$bytes,$e,$d,$c)->prepared();$body=$p['body'];$p['body']=&$body;
  $i=new MutationIntent('IMAGE_UPLOAD',$s,$p,$c);$body['file_name']='changed-after-review.png';
  self::assertSame('shop2-ffffffffffffffff.png',$i->prepared()['body']['file_name']);
 }
 public function testExternalRevisionReferenceCannotChangeAnAlreadyConstructedIntent():void {
  [$s,$bytes,$e,$d,$c]=$this->fixture();$p=(new PreparationService())->uploadFromCoreDescriptor($s,$bytes,$e,$d,$c)->prepared();$revision=0;$c['product_version']=&$revision;$c['expected_product_version']=&$revision;
  $i=new MutationIntent('IMAGE_UPLOAD',$s,$p,$c);$revision=8;
  self::assertSame(0,$i->context['product_version']);self::assertSame(0,$i->context['expected_product_version']);
 }
 public function testCanonicalHashDoesNotReorderCallerOwnedReferencedMaps():void {
  $body=['z'=>1,'a'=>2];$input=['body'=>&$body];$fingerprint=MutationIntent::hash($input);
  self::assertSame(hash('sha256','{"body":{"a":2,"z":1}}'),$fingerprint);self::assertSame(['z','a'],array_keys($body));
 }
 public function testFingerprintingNeverRunsPhpSerializationCallbacks():void {
  $value=new class implements JsonSerializable {public function jsonSerialize():mixed{throw new LogicException('Fingerprint callback must never execute');}};
  $this->expectException(InvalidArgumentException::class);MutationIntent::hash(['nested'=>['value'=>$value]]);
 }
}
