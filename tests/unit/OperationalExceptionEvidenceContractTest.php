<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class OperationalExceptionEvidenceContractTest extends TestCase {
 public function testExceptionProjectionIsBoundedAndNonAuthorizing():void {
  $c=file_get_contents(__DIR__.'/../../includes/Operations/OperationalExceptionReadModel.php');
  foreach(['min(100,','fulfillment_intents','finance_ledger','tax_classifications',"'retry_permitted']=false","'external_execution_authorized']=false","'money_movement_authorized']=false","'tax_filing_authorized']=false"] as $v) self::assertStringContainsString($v,$c);
  self::assertStringNotContainsString('input_payload',$c);
  self::assertStringNotContainsString('evidence longtext',$c);
 }
 public function testPortalMakesExceptionEvidenceNonExecutable():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(['Fulfillment exceptions requiring evidence review','Finance / GST exceptions','Money movement authority</span><b>NO','Tax filing authority</span><b>NO','Exception evidence never files GST/tax, changes tax classification, issues refunds, or moves money.'] as $v) self::assertStringContainsString($v,$c);
 }
}