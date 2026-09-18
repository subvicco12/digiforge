<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ControlledExecutionTransactionStructureTest extends TestCase{
 public function testTransactionHasOneTerminalPersistencePathPerAdapterOutcome():void{
  $s=file_get_contents(dirname(__DIR__,2).'/includes/POD/ControlledExecutionTransaction.php');self::assertIsString($s);
  foreach(['$adapter->execute','ExecutionAdapterFailure::fromError','ExecutionAdapterResult::normalize','ExecutionFailureRepository::save','ExecutionReceiptRepository::save',"'nonce_consumed'=>true"] as $x)self::assertStringContainsString($x,$s);
  self::assertLessThan(strpos($s,'ExecutionFailureRepository::save'),strpos($s,'ExecutionAdapterResult::normalize'));
  self::assertStringNotContainsString('wp_remote_',$s);
 }
}
