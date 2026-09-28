<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PolicyHistoryRecoveryCorrelationContractTest extends TestCase {
 public function testPolicyHistoryAndCurrentAreBoundedReadOnlyEvidence():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/ScopedCapabilityPolicyRepository.php');
  foreach(['min(200,','ORDER BY id DESC LIMIT %d','MAX(policy_version)',"'read_only_evidence']=true","'retry_permitted']=false","'external_execution_authorized']=false"] as $v)self::assertStringContainsString($v,$c);
 }
 public function testRecoveryCorrelationCannotRecoverReplayOrRetry():void {
  $c=file_get_contents(__DIR__.'/../../includes/Queue/OperatorQueueReadModel.php');
  foreach(["'requires_operator_review'=>","'automatic_recovery_permitted'=>false","'replay_permitted'=>false","'retry_permitted'=>false","'external_execution_authorized'=>false"] as $v)self::assertStringContainsString($v,$c);
 }
 public function testPortalMakesPolicyAndRecoveryAuthorityExplicit():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(['Current scoped policies','Recent policy evidence','Policy execution authority','cannot override STOP ALL','Correlated recovery evidence','Automatic recovery authority: NO'] as $v)self::assertStringContainsString($v,$c);
 }
}