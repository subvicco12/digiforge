<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ProductReviewQaEvidenceAvailabilityContractTest extends TestCase {
 public function testGate2CountReadsMustRejectUnavailableEvidenceBeforeApprovalWrites():void {
  $c=file_get_contents(__DIR__.'/../../includes/ProductFactory/ProductReview.php');
  self::assertStringNotContainsString('(int) $wpdb->get_var',$c);
  self::assertGreaterThanOrEqual(4,substr_count($c,"qa_evidence_unavailable"));
  self::assertStringContainsString("Product Approval QA evidence is unavailable; approval is blocked.",$c);
  self::assertStringContainsString("Required asset QA evidence is unavailable; approval is blocked.",$c);
  $qa=strpos($c,'$planQa = $this->planQaPassed');
  $transition=strpos($c,"transition('revision'");
  self::assertNotFalse($qa);self::assertNotFalse($transition);self::assertLessThan($transition,$qa);
 }
 public function testGate2QaCountReadsCheckDatabaseErrorsAndNumericEvidence():void {
  $c=file_get_contents(__DIR__.'/../../includes/ProductFactory/ProductReview.php');
  self::assertGreaterThanOrEqual(4,substr_count($c,"!empty($wpdb->last_error)||!is_numeric("));
 }
}
