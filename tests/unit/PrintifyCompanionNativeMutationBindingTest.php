<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForgePrintifyConnector\Preparation\{MutationIntent,PreparationService,NativePortAdapter,VersionedMutationHost,ProviderReceipt};
require_once __DIR__.'/../fixtures/printify-connector/stage34/companion-test-bootstrap.php';
require_once __DIR__.'/../fixtures/printify-connector/batch6/SyntheticNativeCore.php';
final class PrintifyCompanionNativeMutationBindingTest extends TestCase {
 private function intent(string $op):MutationIntent {
  if($op==='IMAGE_UPLOAD')return (require __DIR__.'/../fixtures/printify-connector/batch2/intent.php')();
  $service=new PreparationService();
  if($op==='PRODUCT_PUBLISH'){[$s,$in,$e,$c]=(require __DIR__.'/../fixtures/printify-connector/batch4/fixture.php')();return $service->publish($s,$in,$e,$c);}
  [$s,$in,$e]=(require __DIR__.'/../fixtures/printify-connector/stage34/product-fixture.php')();
  if($op==='PRODUCT_UPDATE'){$e['product_version']=1;$e['expected_product_version']=1;$e['current_product_id']='fixture_product';$in['product_id']='fixture_product';$in['known_variant_ids']=[99005];}
  return $service->product($op,$s,$in,$e,['actor_id'=>1,'logical_reference'=>'binding-product','approval_reference'=>$e['approval_receipt_sha256'],'authorization_revision'=>7,'product_version'=>$e['product_version'],'expected_product_version'=>$e['expected_product_version']]);
 }
 private function request(MutationIntent $i,string $action,array $data):array{return ['contract_version'=>'1.0.0','action'=>$action,'binding'=>VersionedMutationHost::binding($i),'context'=>$i->context,'evidence_references'=>$i->evidenceReferences,'duplicate_key'=>$i->duplicateKey,'data'=>$data];}
 public static function operations():array{return [['IMAGE_UPLOAD'],['PRODUCT_CREATE'],['PRODUCT_UPDATE'],['PRODUCT_PUBLISH']];}
 /** @dataProvider operations */
 public function testUnmodifiedRegistrationReachesOnlyCore(string $op):void{$i=$this->intent($op);$c=new SyntheticNativeCore();$c->safetyStop=false;$c->externalLock=false;$r=(new NativePortAdapter($c))->exchange($this->request($i,'REGISTER',['prepared'=>$i->prepared()]));self::assertIsArray($r);self::assertSame(1,$c->writeCalls);self::assertSame('PREPARED',$r['data']['state']);}
 /** @dataProvider operations */
 public function testRegistrationRejectsInconsistentImmutableIdentityBeforeCore(string $op):void{
  foreach(['payload_fingerprint','intent_fingerprint','operation_id','duplicate_key']as $field){$i=$this->intent($op);$q=$this->request($i,'REGISTER',['prepared'=>$i->prepared()]);if($field==='duplicate_key')$q[$field]=str_repeat('0',64);else $q['binding'][$field]=str_repeat('0',64);$c=new SyntheticNativeCore();$c->safetyStop=false;$c->externalLock=false;self::assertInstanceOf(WP_Error::class,(new NativePortAdapter($c))->exchange($q),$op.':'.$field);self::assertSame(0,$c->writeCalls);}
 }
 /** @dataProvider operations */
 public function testReceiptIdentityMismatchNeverReachesCore(string $op):void{
  $i=$this->intent($op);$source=new SyntheticMutationCore();$receipt=$source->receipt($i);
  foreach(['operation_id','payload_fingerprint','intent_fingerprint','scope_fingerprint','shop_key','integration_id','credential_reference','provider_shop_id','approval_reference','authorization_revision','product_version']as $field){$r=$receipt;$r[$field]=is_int($r[$field])?$r[$field]+1:($field==='shop_key'?'standard_pod':($field==='provider_shop_id'?'99999':str_repeat('0',64)));$r['receipt_hash']=ProviderReceipt::hash($r);$q=$this->request($i,'RECONCILE',['receipt'=>$r,'metadata'=>[]]);$c=new SyntheticNativeCore();$c->safetyStop=false;$c->externalLock=false;self::assertInstanceOf(WP_Error::class,(new NativePortAdapter($c))->exchange($q));self::assertSame(0,$c->writeCalls,$op.':'.$field);}
 }
 /** @dataProvider operations */
 public function testBoundSyntheticLifecyclePreservesUnknownAndTrustedImport(string $op):void{
  $i=$this->intent($op);$c=new SyntheticNativeCore();$c->safetyStop=false;$c->externalLock=false;$h=new VersionedMutationHost(new NativePortAdapter($c));$f=new \DigiForgePrintifyConnector\Preparation\MutationFence($h);
  self::assertSame('PREPARED',$f->register($i)['state']);self::assertSame('FENCED',$f->reserve($i)['state']);self::assertInstanceOf(WP_Error::class,$f->reserve($i));
  $r=$c->mutation->receipt($i);$u=$r;$u['kind']='UNKNOWN';$u['body']=[];$u['http_status']=0;$u['receipt_hash']=ProviderReceipt::hash($u);
  self::assertSame('UNKNOWN',$h->acceptReceipt($i,$u)['state']);self::assertInstanceOf(WP_Error::class,$f->reserve($i));
  $r['sequence']=2;$r['event_id']=hash('sha256',$r['event_id'].':readback');$r['receipt_hash']=ProviderReceipt::hash($r);
  self::assertSame('METADATA_RECONCILED',$h->acceptReceipt($i,$r)['state']);$count=$c->mutation->receiptCount;
  $ticket=json_encode(['schema'=>'DF_TRUSTED_RECEIPT_IMPORT_1','operation_id'=>$i->operationId,'scope_fingerprint'=>$i->scope->fingerprint(),'payload_fingerprint'=>$i->payloadFingerprint,'receipt_hash'=>$r['receipt_hash']],JSON_THROW_ON_ERROR);
  self::assertSame('METADATA_RECONCILED',(new \DigiForgePrintifyConnector\Preparation\ReceiptImporter($h))->import($i,$ticket)['state']);self::assertSame($count,$c->mutation->receiptCount);
  $bad=$r;$bad['sequence']=3;$bad['receipt_hash']=ProviderReceipt::hash($bad);self::assertInstanceOf(WP_Error::class,$h->acceptReceipt($i,$bad));self::assertSame($count,$c->mutation->receiptCount);
 }

 /** @dataProvider operations */
 public function testCorruptReceiptDigestNeverReachesCore(string $op):void{$i=$this->intent($op);$r=(new SyntheticMutationCore())->receipt($i);$r['receipt_hash']=str_repeat('0',64);$c=new SyntheticNativeCore();$c->safetyStop=false;$c->externalLock=false;self::assertInstanceOf(WP_Error::class,(new NativePortAdapter($c))->exchange($this->request($i,'RECONCILE',['receipt'=>$r,'metadata'=>[]])));self::assertSame(0,$c->writeCalls);}

}
