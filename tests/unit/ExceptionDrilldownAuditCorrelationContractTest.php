<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ExceptionDrilldownAuditCorrelationContractTest extends TestCase {
 public function testExceptionsCarryOnlyNonSecretAuditIdentity():void {
  $c=file_get_contents(__DIR__.'/../../includes/Operations/OperationalExceptionReadModel.php');
  foreach(["'object_type'=>'fulfillment_intent'","'object_type'=>'finance_ledger'","'object_type'=>'tax_classification'","'object_id'=>(string)\$r['id']"] as $v) self::assertStringContainsString($v,$c);
  self::assertStringNotContainsString("'context'=>",$c);
 }
 public function testDrilldownsNavigateWithoutExecutionControls():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(['fulfillment_intent:','finance_ledger:','tax_classification:','Open audit/reconciliation'] as $v) self::assertStringContainsString($v,$c);
  self::assertStringNotContainsString('Execute fulfillment',$c);
  self::assertStringNotContainsString('File GST',$c);
  self::assertStringNotContainsString('Issue refund',$c);
 }
}