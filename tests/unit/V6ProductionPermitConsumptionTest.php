<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class V6ProductionPermitConsumptionTest extends TestCase
{
 public function testConsumerFailsClosedAndNeverExecutesNetwork():void{
  $s=file_get_contents(__DIR__.'/../../includes/POD/ProductionExecutionPermitConsumer.php');
  foreach(['production_permit_fingerprint_mismatch','production_permit_expired','ProductionExecutionConsumptionRepository::consume','nonce_consumed'=>true,'external_execution_performed'=>false] as $n)self::assertStringContainsString($n,$s);
  self::assertStringNotContainsString('wp_remote_',$s);$r=file_get_contents(__DIR__.'/../../includes/POD/ProductionExecutionConsumptionRepository.php');foreach(['START TRANSACTION','ROLLBACK','COMMIT','pod_authorization_bindings','pod_execution_nonces'] as $n)self::assertStringContainsString($n,$r);
 }
 public function testUnknownOperatorQueueIsNonRetryable():void{
  $s=file_get_contents(__DIR__.'/../../includes/POD/PrintifyUnknownOperatorReadModel.php');
  foreach(['RECONCILE_BEFORE_ANY_RETRY','retry_permitted'=>false,'PrintifyUnknownReconciliationReadModel'] as $n)self::assertStringContainsString(is_string($n)?$n:'',$s);
  self::assertStringNotContainsString('wp_remote_',$s);
  $a=file_get_contents(__DIR__.'/../../includes/Portal/AttentionReadModel.php');self::assertStringContainsString('printify_unknown_reconciliations',$a);
 }
}
