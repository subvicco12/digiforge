<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class V6ProductionOutcomeLinkageTest extends TestCase
{
 public function testConsumedPermitProjectionUsesAtomicOutcomeRepository():void{
  $s=file_get_contents(__DIR__.'/../../includes/POD/ConsumedPermitOutcomeReadModel.php');
  foreach(['PERMIT_NOT_CONSUMED','CONSUMED_AWAITING_OUTCOME','ExecutionOutcomeRepository::findByAuthorizationHash','EXECUTION_UNKNOWN','external_execution_performed'] as $n)self::assertStringContainsString($n,$s);
  self::assertStringNotContainsString('wp_remote_',$s);
 }
 public function testOutcomeClaimsRemainMutuallyExclusive():void{
  $s=file_get_contents(__DIR__.'/../../includes/POD/ExecutionOutcomeClaimRepository.php');
  self::assertStringContainsString("['SUCCEEDED','FAILED','UNKNOWN']",$s);self::assertStringContainsString('digiforge_outcome_claim_conflict',$s);
 }
 public function testReconciliationAttentionSeparatesUnresolvedFromReview():void{
  $s=file_get_contents(__DIR__.'/../../includes/POD/PrintifyUnknownOperatorReadModel.php');
  self::assertStringContainsString('resolved_review_required',$s);self::assertStringContainsString('RECONCILE_BEFORE_ANY_RETRY',$s);self::assertStringContainsString('REVIEW_RECONCILIATION_RESULT',$s);
  $a=file_get_contents(__DIR__.'/../../includes/Portal/AttentionReadModel.php');self::assertStringContainsString('printify_reconciliation_reviews',$a);
 }
}
