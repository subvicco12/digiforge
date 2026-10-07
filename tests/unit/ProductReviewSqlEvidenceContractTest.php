<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ProductReviewSqlEvidenceContractTest extends TestCase {
 public function testApprovalReadsResetAndPropagateDatabaseEvidenceFailures():void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/ProductReview.php');
  self::assertStringContainsString('private function plan(int $productVersionId): array|WP_Error|null',$s);
  self::assertStringContainsString('private function bundle(int $planId): array|WP_Error|null',$s);
  self::assertStringContainsString('if (is_wp_error($plan)) { return $plan; }',$s);
  self::assertStringContainsString('if (is_wp_error($bundle)) { return $bundle; }',$s);
  self::assertStringContainsString("'approval_evidence_unavailable'",$s);
  self::assertStringContainsString("'Required asset revision evidence is unavailable; approval is blocked.'",$s);
  self::assertGreaterThanOrEqual(7,substr_count($s,"\$wpdb->last_error = '';"));
 }
}
