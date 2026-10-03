<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ShopAiGovernanceDbEvidenceContractTest extends TestCase {
 public function testGovernanceDatabaseUncertaintyFailsClosed():void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/AI/ShopAiGovernanceRepository.php');
  self::assertGreaterThanOrEqual(7,substr_count($s,"\$wpdb->last_error=''"));
  self::assertStringContainsString("'ai_policy_evidence_unavailable'",$s);
  self::assertStringContainsString("'ai_policy_confirmation_unavailable'",$s);
  self::assertStringContainsString("'ai_usage_evidence_unavailable'",$s);
  self::assertStringContainsString("'ai_usage_confirmation_unavailable'",$s);
  self::assertStringContainsString("!is_numeric(\$raw)",$s);
  self::assertStringNotContainsString("(float)\$wpdb->get_var",$s);
 }
 public function testRunContextAndPolicyAbsenceSemanticsRemainDistinct():void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/AI/ShopAiGovernanceRepository.php');
  self::assertStringContainsString("'ai_policy_missing'",$s);
  self::assertStringContainsString('EXPLICIT_RUN_CONTEXT_REQUIRED',$s);
  self::assertStringContainsString("'authoritative'=>\$explicit",$s);
 }
}