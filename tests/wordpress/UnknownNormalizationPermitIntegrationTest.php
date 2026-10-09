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
}
