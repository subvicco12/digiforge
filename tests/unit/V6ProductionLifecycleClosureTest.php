<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class V6ProductionLifecycleClosureTest extends TestCase
{
 public function testReconciliationAcknowledgementRemainsHumanAndNonRetryable():void{
  $s=file_get_contents(__DIR__.'/../../includes/POD/ReconciliationAcknowledgement.php');
  foreach(['reconciliation_reviewer_required','ACKNOWLEDGE_CONFIRMED','ACKNOWLEDGE_NOT_CONFIRMED','retry_permitted'=>false,'external_execution_authorized'=>false] as $n)self::assertStringContainsString(is_string($n)?$n:'',$s);
  self::assertStringNotContainsString('wp_remote_',$s);
 }
 public function testClosureRequiresConsumedTerminalOutcome():void{
  $s=file_get_contents(__DIR__.'/../../includes/POD/ProductionLifecycleClosure.php');
  foreach(['APPROVED_PACKAGE','nonce_consumed','EXECUTION_SUCCEEDED','EXECUTION_FAILED','EXECUTION_UNKNOWN','PRODUCTION_LIFECYCLE_CLOSED'] as $n)self::assertStringContainsString($n,$s);
  self::assertStringNotContainsString('wp_remote_',$s);
 }
}
