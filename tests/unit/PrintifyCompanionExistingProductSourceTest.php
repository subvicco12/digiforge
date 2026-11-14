<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForgePrintifyConnector\Preparation\{ExistingProductSourceReadModel,ExistingProductSourcePanel,MutationIntent,NativePortAdapter};
require_once __DIR__.'/../fixtures/printify-connector/stage34/companion-test-bootstrap.php';
final class PrintifyCompanionExistingProductSourceTest extends TestCase {
 private function fixture():array{return (require __DIR__.'/../fixtures/printify-connector/sprint/existing-product-source.php')();}
 public function testPriceOnlyObservationCannotInventCurrencyNativeVersionOrAuthority():void {
  self::assertTrue(class_exists(ExistingProductSourceReadModel::class),'The actual core price-only source needs a minimized non-authorizing view.');
  [$s,$r]=$this->fixture();$v=ExistingProductSourceReadModel::observe($s,1,$r);self::assertNotInstanceOf(WP_Error::class,$v);
  self::assertSame('PRICE_ONLY_SOURCE_OBSERVED_MUTATION_BLOCKED',$v['state']);self::assertSame('PRODUCT_UPDATE',$v['operation']);self::assertSame('PRICE_ONLY',$v['payload_scope']);self::assertSame('synthetic-product',$v['provider_product_id']);self::assertSame(2199,$v['price_minor']);self::assertNull($v['currency']);self::assertNull($v['native_product_version']);self::assertSame(6,$v['business_product_version_id']);self::assertSame($r['observed_at'],$v['observed_at']);self::assertSame($r['adopted_at'],$v['adopted_at']);self::assertSame($r['evidence_hash'],$v['source_evidence_hash']);
  foreach(['mutation_authorized','dispatch_permitted','provider_currentness_verified','provider_version_cas_verified','source_authenticated','trusted_receipt','network_request_permitted','external_execution_authorized','full_product_draft_equivalence_verified','native_version_counters_verified','independent_checkpoint_verified','source_approval_cas_verified','supplier_certified']as $k)self::assertFalse($v[$k],$k);
  foreach(['current_core_verification_required','manual_review_required','revalidation_before_mutation_required','read_only']as $k)self::assertTrue($v[$k],$k);
  foreach(['body','contents','credential_reference','adoption_producer_actor_id','product_read_receipt_hash','plan_policy_hash']as $k)self::assertArrayNotHasKey($k,$v);
 }
 /** @dataProvider invalidSource */
 public function testRehashedWrongIdentityOrAuthorityStillDenies(string $key,mixed $value):void {
  [$s,$r]=$this->fixture();$r[$key]=$value;unset($r['evidence_hash']);$r['evidence_hash']=MutationIntent::hash($r);self::assertInstanceOf(WP_Error::class,ExistingProductSourceReadModel::observe($s,1,$r));
 }
 public static function invalidSource():array {
  $r=[];foreach(['mutation_authorized','dispatch_permitted','independent_checkpoint_verified','provider_currentness_verified','provider_version_cas_verified','business_package_binding_approved','source_approval_cas_verified','supplier_certified','external_execution_authorized']as $k)$r[$k]=[$k,true];
  foreach(['release_hold','revalidation_before_mutation_required']as $k)$r[$k]=[$k,false];
  foreach(['actor_id'=>2,'shop_key'=>'standard_pod','integration_id'=>8,'credential_reference'=>8,'provider_shop_id'=>'13','environment'=>'production','schema'=>'other','version'=>'2.0.0','operation'=>'PRODUCT_CREATE','native_product_version'=>6,'currency'=>'USD','price_minor'=>0,'product_version_id'=>0,'variant_id'=>'99005','provider_product_id'=>'<script>','source_read_policy_revision'=>0,'adoption_producer_audit_id'=>10,'observed_at'=>'2026-02-30T00:00:00+00:00']as $k=>$v)$r[$k]=[$k,$v];return $r;
 }
 public function testWrongActorExpiredOrForeignScopeAndTamperedHashDeny():void {
  [$s,$r]=$this->fixture();self::assertInstanceOf(WP_Error::class,ExistingProductSourceReadModel::observe($s,2,$r));
  foreach([[2,'personalized_pod',time()-1],[3,'standard_pod',2000000000]]as [$id,$key,$expiry]){$foreign=new DigiForge\Connector\Printify\ShopBinding($id,$key,7,'12','test',3,new DigiForge\Connector\Printify\CredentialReference(7),str_repeat('a',64),$expiry);$q=$r;$q['scope_fingerprint']=$foreign->fingerprint();$q['shop_key']=$key;unset($q['evidence_hash']);$q['evidence_hash']=MutationIntent::hash($q);self::assertInstanceOf(WP_Error::class,ExistingProductSourceReadModel::observe($foreign,1,$q));}
  $r['evidence_hash']=str_repeat('f',64);self::assertInstanceOf(WP_Error::class,ExistingProductSourceReadModel::observe($s,1,$r));
 }
 public function testExactSchemaAndObservationTimeAreRequired():void {
  [$s,$r]=$this->fixture();foreach(['missing','extra','future']as $fault){$q=$r;if($fault==='missing')unset($q['observed_at']);elseif($fault==='extra')$q['unreviewed_body']=['private'=>'omit'];else$q['observed_at']=gmdate('c',time()+3600);unset($q['evidence_hash']);$q['evidence_hash']=MutationIntent::hash($q);self::assertInstanceOf(WP_Error::class,ExistingProductSourceReadModel::observe($s,1,$q));}
 }
 public function testExecutableInputIsRejectedWithoutCallingIt():void {
  [$s,$r]=$this->fixture();$calls=0;$r['provider_product_id']=new class($calls) implements JsonSerializable {public function __construct(private int &$calls){}public function jsonSerialize():mixed{++$this->calls;return 'synthetic-product';}};
  self::assertInstanceOf(WP_Error::class,ExistingProductSourceReadModel::observe($s,1,$r));self::assertSame(0,$calls);
 }
 public function testObservationDetachesCallerReferences():void {
  [$s,$r]=$this->fixture();$id=$r['provider_product_id'];$price=$r['price_minor'];$r['provider_product_id']=&$id;$r['price_minor']=&$price;$v=ExistingProductSourceReadModel::observe($s,1,$r);self::assertNotInstanceOf(WP_Error::class,$v);$id='foreign-product';$price=1;self::assertSame('synthetic-product',$v['provider_product_id']);self::assertSame(2199,$v['price_minor']);
 }
 public function testMissingNativePanelAuthorityNeverExposesSourceOrControls():void {
  [$s]=$this->fixture();$p=new ExistingProductSourcePanel(new NativePortAdapter());$plan=str_repeat('a',64);$receipt=str_repeat('b',64);self::assertInstanceOf(WP_Error::class,$p->snapshot($s,1,[],8,9,$plan,$receipt));$html=$p->render($s,1,[],8,9,$plan,$receipt);self::assertStringContainsString('No mutation authorized',$html);foreach(['<form','<button','<input','synthetic-product',$plan,$receipt]as $text)self::assertStringNotContainsString($text,$html);self::assertInstanceOf(WP_Error::class,DigiForgePrintifyConnector\Plugin::existingProductSourcePanel()->snapshot($s,1,[],8,9,$plan,$receipt));
 }
}
