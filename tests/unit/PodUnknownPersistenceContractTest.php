<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PodUnknownPersistenceContractTest extends TestCase{
 public function testUnknownIsPersistedAndDiscoverableBeforeRetry():void{
  $t=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/ControlledExecutionTransaction.php');
  $o=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionOutcomeRepository.php');
  $r=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionUnknownRepository.php');
  self::assertStringContainsString('ExecutionUnknownRepository::save',$t);
  self::assertStringContainsString('pod_execution_unknowns',$o);
  self::assertStringContainsString("'state'=>'EXECUTION_UNKNOWN'",$o);
  foreach(['retry_permitted','reconciliation_required'] as $x){self::assertStringContainsString($x,$o);self::assertStringContainsString($x,$r);}
 }
 public function testAdapterContractMakesPostSendAmbiguityExplicit():void{
  $t=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/ControlledExecutionTransaction.php');
  self::assertStringContainsString('adapters MUST return an explicit UNKNOWN result on timeout/no-response ambiguity',$t);
 }
}
