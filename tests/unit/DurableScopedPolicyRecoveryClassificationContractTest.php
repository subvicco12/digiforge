<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class DurableScopedPolicyRecoveryClassificationContractTest extends TestCase {
 public function testSchema23IsAdditiveAndPolicyEvidenceVersioned():void {
  self::assertGreaterThanOrEqual(23,\DigiForge\Database\MigrationPlan::LATEST);
  $s=file_get_contents(__DIR__.'/../../includes/Database/ScopedPolicySchema.php');foreach(['VERSION=23','scoped_capability_policies','scope_version','previous_policy_hash'] as $v)self::assertStringContainsString($v,$s);
  $r=file_get_contents(__DIR__.'/../../includes/Portal/ScopedCapabilityPolicyRepository.php');foreach(['ORDER BY policy_version DESC LIMIT 1',"'external_execution_authorized'=>false",'previous_policy_hash'] as $v)self::assertStringContainsString($v,$r);
 }
 public function testRecoveryClassificationNeverAuthorizesAction():void {
  $c=file_get_contents(__DIR__.'/../../includes/Queue/OperatorQueueReadModel.php');foreach(['ORPHAN_EVIDENCE_REQUIRES_OPERATOR_REVIEW','RATE_LIMIT_EVIDENCE_REQUIRES_BACKOFF_REVIEW','IDEMPOTENCY_EVIDENCE_REQUIRES_RECONCILIATION',"'automatic_recovery_permitted'=>false","'replay_permitted'=>false","'retry_permitted'=>false"] as $v)self::assertStringContainsString($v,$c);
 }
 public function testActivatorRunsV23AfterV22():void {
  $c=file_get_contents(__DIR__.'/../../includes/Core/Activator.php');self::assertGreaterThan(strpos($c,'V6OperationalSchema::migrateIfNeeded'),strpos($c,'ScopedPolicySchema::migrateIfNeeded'));
 }
}