<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PodRollbackObservabilityContractTest extends TestCase{
 public function testScopeRollbackFailureIsObservableWithoutReplacingPrimaryError():void{
  $s=(string)file_get_contents(__DIR__.'/../../includes/POD/BusinessScopeRepository.php');
  self::assertStringContainsString('rollbackPreservingPrimaryFailure',$s);
  self::assertStringContainsString('pod_scope_rollback_failed',$s);
  self::assertStringContainsString('primary_error_preserved=true',$s);
  self::assertStringContainsString('operator_attention_required=true',$s);
  self::assertStringNotContainsString("\$wpdb->query('ROLLBACK')",$s);
 }
}