<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ProductionPermitRollbackObservabilityContractTest extends TestCase{
 public function testPermitRollbackFailureIsObservableWithoutGrantingAuthority():void{
  $s=(string)file_get_contents(__DIR__.'/../../includes/POD/ProductionExecutionConsumptionRepository.php');
  self::assertStringContainsString('private static function rollback():void',$s);
  self::assertStringContainsString('production_permit_transaction_rollback_failed',$s);
  self::assertStringContainsString('primary_error_preserved=true',$s);
  self::assertStringContainsString('operator_attention_required=true',$s);
  self::assertStringContainsString("'external_execution_authorized'=>false",$s);
  self::assertSame(1,substr_count($s,"query('ROLLBACK')"));
 }
}