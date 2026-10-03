<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class RenderEvidenceAvailabilityContractTest extends TestCase{
 public function testIndependentReadsClearStaleErrorsAndConfirmationsFailClosed():void{
  $s=(string)file_get_contents(__DIR__.'/../../includes/POD/RenderEvidenceRepository.php');
  self::assertGreaterThanOrEqual(5,substr_count($s,"\$wpdb->last_error=''"));
  foreach(['render_evidence_confirmation_unavailable','render_review_confirmation_unavailable',"'retry_permitted'=>false","'external_execution_authorized'=>false"] as $n)self::assertStringContainsString($n,$s);
 }
}