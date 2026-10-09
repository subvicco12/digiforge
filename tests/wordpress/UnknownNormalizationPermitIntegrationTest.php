<?php
declare(strict_types=1);
final class UnknownNormalizationPermitIntegrationTest extends WP_UnitTestCase {
 public function testUnconsumedPermitPreservesPermitErrorForUnknown():void {
  $permit=['state'=>'ADAPTER_CALL_PERMITTED','nonce_consumed'=>false,'external_execution_performed'=>false];
  $result=['status'=>'UNKNOWN','request_fingerprint'=>'','reconciliation_identity'=>[]];
  $normalized=DigiForge\POD\ExecutionAdapterResult::normalize($permit,$result);
  self::assertWPError($normalized);
  self::assertSame('digiforge_adapter_permit',$normalized->get_error_code());
 }
 public function testPostExecutionPermitPreservesStateErrorForUnknown():void {
  $permit=['state'=>'ADAPTER_CALL_PERMITTED','nonce_consumed'=>true,'external_execution_performed'=>true];
  $result=['status'=>'UNKNOWN','request_fingerprint'=>'','reconciliation_identity'=>[]];
  $normalized=DigiForge\POD\ExecutionAdapterResult::normalize($permit,$result);
  self::assertWPError($normalized);
  self::assertSame('digiforge_adapter_permit_state',$normalized->get_error_code());
 }
 public function testUnknownRequiresNonemptyArrayIdentityAndValidFingerprint():void {
  $permit=['state'=>'ADAPTER_CALL_PERMITTED','nonce_consumed'=>true,'external_execution_performed'=>false,'action'=>'ETSY_DRAFT_CREATE','authorization_hash'=>str_repeat('a',64),'evidence_hash'=>str_repeat('b',64)];
  $absent=DigiForge\POD\ExecutionAdapterResult::normalize($permit,['status'=>'UNKNOWN','request_fingerprint'=>str_repeat('c',64)]);
  self::assertWPError($absent);
  self::assertSame('digiforge_adapter_reconciliation_identity',$absent->get_error_code());
  foreach([null,'provider-ref',[],false] as $identity) {
   $normalized=DigiForge\POD\ExecutionAdapterResult::normalize($permit,['status'=>'UNKNOWN','request_fingerprint'=>str_repeat('c',64),'reconciliation_identity'=>$identity]);
   self::assertWPError($normalized);
   self::assertSame('digiforge_adapter_reconciliation_identity',$normalized->get_error_code());
  }
  $normalized=DigiForge\POD\ExecutionAdapterResult::normalize($permit,['status'=>' unknown ','request_fingerprint'=>strtoupper(str_repeat('c',64)),'reconciliation_identity'=>['provider'=>'mock','request_id'=>'request-1']]);
  self::assertIsArray($normalized);
  self::assertSame('UNKNOWN',$normalized['status']);
  self::assertSame(str_repeat('c',64),$normalized['request_fingerprint']);
  self::assertTrue($normalized['reconciliation_required']);
  self::assertFalse($normalized['retry_permitted']);
 }

 public function testUnknownRejectsMalformedFingerprintEvenWithIdentity():void {
  $permit=['state'=>'ADAPTER_CALL_PERMITTED','nonce_consumed'=>true,'external_execution_performed'=>false,'action'=>'ETSY_DRAFT_CREATE','authorization_hash'=>str_repeat('a',64),'evidence_hash'=>str_repeat('b',64)];
  foreach(['',str_repeat('a',63),str_repeat('a',65),str_repeat('g',64),'not-a-sha256',null] as $fingerprint) {
   $result=['status'=>'UNKNOWN','reconciliation_identity'=>['provider'=>'mock','request_id'=>'request-2']];
   if($fingerprint!==null)$result['request_fingerprint']=$fingerprint;
   $normalized=DigiForge\POD\ExecutionAdapterResult::normalize($permit,$result);
   self::assertWPError($normalized);
   self::assertSame('digiforge_adapter_reconciliation_identity',$normalized->get_error_code());
  }
 }

}
