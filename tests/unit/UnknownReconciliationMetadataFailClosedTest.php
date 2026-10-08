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
}
