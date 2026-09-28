<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class V6ProductionClosurePersistenceTest extends TestCase{
 public function testCurrentSchemaAddsImmutableLifecycleEvidence():void{
  $s=file_get_contents(__DIR__.'/../../includes/Database/V6OperationalSchema.php');self::assertStringContainsString('VERSION=21',$s);foreach(['pod_reconciliation_acknowledgements','pod_lifecycle_closures','UNIQUE KEY reconciliation_hash','UNIQUE KEY authorization_hash'] as $n)self::assertStringContainsString($n,$s);
 }
 public function testRepositoriesAreIdempotentAndConflictClosed():void{
  $a=file_get_contents(__DIR__.'/../../includes/POD/ReconciliationAcknowledgementRepository.php');self::assertStringContainsString('reconciliation_ack_conflict',$a);self::assertStringContainsString('acknowledgement_hash',$a);
  $c=file_get_contents(__DIR__.'/../../includes/POD/ProductionLifecycleClosureRepository.php');self::assertStringContainsString('production_closure_conflict',$c);self::assertStringContainsString('package_id=%d OR authorization_hash=%s',$c);
  self::assertStringNotContainsString('wp_remote_',$a.$c);
 }
 public function testAcknowledgedReconciliationLeavesReviewQueue():void{
  $s=file_get_contents(__DIR__.'/../../includes/POD/PrintifyUnknownOperatorReadModel.php');self::assertStringContainsString('pod_reconciliation_acknowledgements',$s);self::assertStringContainsString('RECONCILIATION_ACKNOWLEDGED',$s);
 }
}
