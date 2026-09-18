<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class TerminalOutcomeExclusivityStructureTest extends TestCase{
 public function testSuccessAndFailureRepositoriesRejectOppositeTerminalState():void{
  // One authorization must never acquire two contradictory terminal outcomes.
  $root=dirname(__DIR__,2).'/includes/POD/';
  $success=file_get_contents($root.'ExecutionReceiptRepository.php');
  $failure=file_get_contents($root.'ExecutionFailureRepository.php');
  self::assertIsString($success);self::assertIsString($failure);
  self::assertStringContainsString('Tables::pod_execution_failures()',$success);
  self::assertStringContainsString('Tables::pod_execution_receipts()',$failure);
  self::assertStringContainsString('digiforge_terminal_outcome_conflict',$success);
  self::assertStringContainsString('digiforge_terminal_outcome_conflict',$failure);
  self::assertStringContainsString("['status'=>409]",$success);
  self::assertStringContainsString("['status'=>409]",$failure);
 }
}
