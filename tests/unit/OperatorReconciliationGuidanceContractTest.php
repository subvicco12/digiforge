<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class OperatorReconciliationGuidanceContractTest extends TestCase {
 public function testAttentionAggregatesEtsyAndPodReconciliationWithoutAuthority():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/AttentionReadModel.php');
  self::assertStringContainsString('etsy_operations_requiring_reconciliation',$c);
  self::assertStringContainsString('RECONCILE_BEFORE_ANY_RETRY',$c);
  self::assertStringContainsString("'retry_permitted'=>false",$c);
  self::assertStringContainsString("'external_execution_authorized'=>false",$c);
 }
 public function testQueueGuidanceCannotTurnFailureIntoRetryPermission():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  self::assertStringContainsString('UNKNOWN external outcomes must be reconciled before any retry.',$c);
  self::assertStringContainsString('are evidence for operator inspection, not retry permission.',$c);
  self::assertStringNotContainsString('Retry job',$c);
 }
}