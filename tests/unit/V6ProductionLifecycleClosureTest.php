<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class V6ProductionLifecycleClosureTest extends TestCase
{
 public function testReconciliationAcknowledgementRemainsHumanAndNonRetryable():void{
  $s=file_get_contents(__DIR__.'/../../includes/POD/ReconciliationAcknowledgement.php');
  foreach(['reconciliation_reviewer_required','ACKNOWLEDGE_CONFIRMED','ACKNOWLEDGE_NOT_CONFIRMED'] as $n)self::assertStringContainsString($n,$s);
  self::assertStringContainsString("'retry_permitted'=>false",$s);
  self::assertStringContainsString("'external_execution_authorized'=>false",$s);
  self::assertStringNotContainsString('wp_remote_',$s);
 }
 public function testClosureRequiresConsumedUnambiguousTerminalOutcome():void{
  $s=file_get_contents(__DIR__.'/../../includes/POD/ProductionLifecycleClosure.php');
  foreach(['APPROVED_PACKAGE','nonce_consumed','EXECUTION_SUCCEEDED','EXECUTION_FAILED','PRODUCTION_LIFECYCLE_CLOSED','CONFIRMED_SUCCESS','CONFIRMED_FAILURE'] as $n)self::assertStringContainsString($n,$s);
  self::assertStringNotContainsString("'EXECUTION_UNKNOWN'",$s);
  self::assertStringNotContainsString('wp_remote_',$s);
 }
}
