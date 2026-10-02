<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class AiHumanApprovalEvidenceAvailabilityContractTest extends TestCase{
 public function testApprovalTransitionSeparatesUnavailableEvidenceFromMissingApproval():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/AI/Repository.php');
  self::assertStringContainsString('private function hasApproval(int $runId, string $targetType): bool|\\WP_Error',$s);
  self::assertStringContainsString("\$wpdb->last_error = ''",$s);
  self::assertStringContainsString('Human approval evidence could not be read.',$s);
  self::assertStringContainsString("return \$this->error('evidence_unavailable'",$s);
  self::assertStringContainsString('if (is_wp_error($approval))',$s);
  self::assertStringContainsString("return \$this->error('review_required', 'Human approval is required.', 409);",$s);
 }
}
