<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class ControlledExecutionTransactionStructureTest extends TestCase
{
 public function testTransactionCannotBypassGateAdapterNormalizationOrReceiptPersistence():void
 {
  $s=file_get_contents(dirname(__DIR__,2).'/includes/POD/ControlledExecutionTransaction.php');
  self::assertIsString($s);
  foreach([
   'ExecutionOrchestrator::prepare',
   'ADAPTER_CALL_PERMITTED',
   'nonce_consumed',
   '$adapter->execute',
   'ExecutionAdapterResult::normalize',
   "'SUCCEEDED'",
   'ExecutionReceipt::record',
   'ExecutionReceiptRepository::save',
   "'EXECUTION_PERSISTED'"
  ] as $required)self::assertStringContainsString($required,$s);
  self::assertStringNotContainsString('wp_remote_',$s);
  self::assertStringNotContainsString('curl_init',$s);
 }
}
