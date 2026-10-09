<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class UnknownReconciliationMetadataFailClosedTest extends TestCase {
 public function testIncompleteUnknownEvidenceNeverReturnsRetryPermission():void {
  $s=(string)file_get_contents(__DIR__.'/../../includes/POD/ControlledExecutionTransaction.php');
  self::assertStringContainsString("digiforge_transaction_reconciliation_evidence_unavailable",$s);
  self::assertStringContainsString("if(\$normalized->get_error_code()==='digiforge_adapter_reconciliation_identity')",$s);
  self::assertStringContainsString("'retry_permitted'=>false,'reconciliation_required'=>true,'external_execution_authorized'=>false",$s);
  self::assertStringContainsString('ExecutionUnknownRepository::save',$s);
 }
 public function testUnknownStatusDoesNotMaskInvalidPermit():void {
  $permit=['state'=>'ADAPTER_CALL_PERMITTED','nonce_consumed'=>false,'external_execution_performed'=>false];
  $result=['status'=>'UNKNOWN','request_fingerprint'=>'','reconciliation_identity'=>[]];
  $normalized=DigiForge\POD\ExecutionAdapterResult::normalize($permit,$result);
  self::assertInstanceOf(WP_Error::class,$normalized);
  self::assertSame('digiforge_adapter_permit',$normalized->get_error_code());
 }
 public function testUnknownStatusDoesNotMaskInvalidPreExecutionState():void {
  $permit=['state'=>'ADAPTER_CALL_PERMITTED','nonce_consumed'=>true,'external_execution_performed'=>true];
  $result=['status'=>'UNKNOWN','request_fingerprint'=>'','reconciliation_identity'=>[]];
  $normalized=DigiForge\POD\ExecutionAdapterResult::normalize($permit,$result);
  self::assertInstanceOf(WP_Error::class,$normalized);
  self::assertSame('digiforge_adapter_permit_state',$normalized->get_error_code());
 }

}
