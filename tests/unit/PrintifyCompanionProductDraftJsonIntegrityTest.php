<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForgePrintifyConnector\Preparation\ProductDraftContract;
use DigiForgePrintifyConnector\Preparation\PreparationService;
require_once __DIR__.'/../fixtures/printify-connector/stage34/companion-test-bootstrap.php';
/** Real draft contracts must reject executable evidence before their own fingerprinting. */
final class PrintifyCompanionProductDraftJsonIntegrityTest extends TestCase
{
 private function fixture():array{return (require __DIR__.'/../fixtures/printify-connector/stage34/product-fixture.php')();}
 public function testPrepareRejectsExecutableNestedEvidenceWithoutCallingIt():void
 {
  [$scope,$input,$evidence]=$this->fixture();$calls=0;
  $evidence['owned_uploads'][0]['note']=new class($calls) implements JsonSerializable {
   public function __construct(private int &$calls){}
   public function jsonSerialize():mixed{++$this->calls;return ['not'=>'plain evidence'];}
  };
  $denied=false;try{ProductDraftContract::prepare('PRODUCT_CREATE',$scope,$input,$evidence);}catch(InvalidArgumentException){$denied=true;}
  self::assertSame(0,$calls,'Untrusted evidence cannot execute during draft fingerprinting.');self::assertTrue($denied);
 }
 public function testReconcileRejectsExecutableEvidenceBeforeTestingItsFingerprint():void
 {
  [$scope,$input,$evidence,$response]=$this->fixture();$request=ProductDraftContract::prepare('PRODUCT_CREATE',$scope,$input,$evidence);$calls=0;
  $request['evidence']['owned_uploads'][0]['note']=new class($calls) implements JsonSerializable {
   public function __construct(private int &$calls){}
   public function jsonSerialize():mixed{++$this->calls;return ['not'=>'plain evidence'];}
  };
  $denied=false;try{ProductDraftContract::reconcile($request,$response);}catch(InvalidArgumentException){$denied=true;}
  self::assertSame(0,$calls,'A mismatched request hash cannot first execute PHP callbacks.');self::assertTrue($denied);
 }
 public function testDraftFingerprintingDoesNotReorderCallerOwnedReferencedMetadata():void
 {
  [$scope,$input,$evidence]=$this->fixture();$metadata=['z'=>'last','a'=>'first'];$evidence['owned_uploads'][0]['note']=&$metadata;
  $request=ProductDraftContract::prepare('PRODUCT_CREATE',$scope,$input,$evidence);
  self::assertSame(['z','a'],array_keys($metadata));self::assertFalse($request['network_request_permitted']);self::assertFalse($request['external_execution_authorized']);
 }
 public function testServiceRejectsExecutableEvidenceBeforeTheIntentConstructor():void
 {
  [$scope,$input,$evidence]=$this->fixture();$calls=0;
  $evidence['owned_uploads'][0]['note']=new class($calls) implements JsonSerializable {
   public function __construct(private int &$calls){}
   public function jsonSerialize():mixed{++$this->calls;return null;}
  };
  $context=['actor_id'=>1,'logical_reference'=>'product-json-fixture','approval_reference'=>$evidence['approval_receipt_sha256'],'authorization_revision'=>7,'product_version'=>0,'expected_product_version'=>0];
  $denied=false;try{(new PreparationService())->product('PRODUCT_CREATE',$scope,$input,$evidence,$context);}catch(InvalidArgumentException){$denied=true;}
  self::assertSame(0,$calls);self::assertTrue($denied);
 }
 public function testExistingValidDraftFingerprintAndReadbackRemainCompatible():void
 {
  [$scope,$input,$evidence,$response]=$this->fixture();$request=ProductDraftContract::prepare('PRODUCT_CREATE',$scope,$input,$evidence);
  // Independently calculated from this fixed fixture using Python canonical JSON with PHP slash escaping.
  self::assertSame('b7a4fccfbc2400cb200ac34405b3165cf18bdb66c0d44e998e9f520d2894fa60',$request['fingerprint']);
  self::assertSame('PRODUCT_METADATA_OBSERVED_UNCERTIFIED',ProductDraftContract::reconcile($request,$response)['state']);
 }
 public function testValidUnicodeSlashAndFractionalMetadataKeepsTheExistingHashProfile():void
 {
  [$scope,$input,$evidence]=$this->fixture();$evidence['owned_uploads'][0]['note']=['z'=>0.0,'a'=>'Ω/東京'];
  $request=ProductDraftContract::prepare('PRODUCT_CREATE',$scope,$input,$evidence);
  self::assertSame('2748b84414a22d6ea5d74bd246e0983324135e702af153eb8c371a4fc9680e4d',$request['fingerprint']);self::assertFalse($request['external_execution_authorized']);
 }
}
