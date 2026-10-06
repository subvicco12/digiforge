<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class FinalAiPolicyPortalEvidenceContractTest extends TestCase {
 public function testHierarchyExposesPolicyEvidenceAvailabilityWithoutGrantingAuthority():void {
  $c=(string)file_get_contents(__DIR__.'/../../includes/Portal/HierarchicalPolicyReadModel.php');
  foreach(["'shop_ai_policy_query_state'","'shop_ai_policy_error'","'policy_evidence_available'","'POLICY_UNAVAILABLE'","'policy_error'","'PARTIAL_UNAVAILABLE'","'unavailable_scenarios'","'external_execution_authorized'=>false"] as $needle) self::assertStringContainsString($needle,$c);
 }
 public function testShopAiRepositoryRequiresExplicitRunContextForPositiveRunBudget():void {
  $c=(string)file_get_contents(__DIR__.'/../../includes/AI/ShopAiGovernanceRepository.php');
  foreach(['EXPLICIT_RUN_CONTEXT_REQUIRED',"'authoritative'=>$explicit",'$runBudget>0&&!$explicit','ai_policy_evidence_unavailable'] as $needle) self::assertStringContainsString($needle,$c);
 }
}
