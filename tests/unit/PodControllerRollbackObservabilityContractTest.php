<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PodControllerRollbackObservabilityContractTest extends TestCase{
 public function testSharedMappingRollbackFailureIsObservableWithoutReplacingPrimaryError():void{
  $s=(string)file_get_contents(__DIR__.'/../../includes/REST/PodController.php');
  self::assertStringContainsString('rollbackMappingTransaction',$s);
  self::assertStringContainsString('pod_mapping_transaction_rollback_failed',$s);
  self::assertStringContainsString('primary_error_preserved=true',$s);
  self::assertStringContainsString('operator_attention_required=true',$s);
  self::assertSame(1,substr_count($s,"query('ROLLBACK')"));
 }
}