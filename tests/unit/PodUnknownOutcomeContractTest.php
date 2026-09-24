<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PodUnknownOutcomeContractTest extends TestCase{
 public function testUnknownOutcomeRequiresReconciliationAndNeverRetries():void{
  $r=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionAdapterResult.php');
  $t=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/ControlledExecutionTransaction.php');
  $u=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionUnknownRecord.php');
  self::assertStringContainsString("['SUCCEEDED','FAILED','UNKNOWN']",$r);
  foreach(['reconciliation_required','retry_permitted'] as $x){self::assertStringContainsString($x,$r);self::assertStringContainsString($x,$t);self::assertStringContainsString($x,$u);}
  self::assertStringContainsString('ExecutionUnknownRecord::record',$t);
  self::assertStringNotContainsString('sleep(',$t);self::assertStringNotContainsString('wp_remote_',$t);
 }
}
