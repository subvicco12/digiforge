<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class RenderEvidenceAvailabilityContractTest extends TestCase {
 public function testRenderPrerequisiteReadsFailClosed():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/RenderEvidenceRepository.php');
  self::assertStringContainsString('render_evidence_unavailable',$c);
  self::assertGreaterThanOrEqual(3,substr_count($c,'!empty($wpdb->last_error)'));
  self::assertStringContainsString("'status'=>503",$c);
  self::assertStringContainsString("'retry_permitted'=>false",$c);
  self::assertStringContainsString("'external_execution_authorized'=>false",$c);
  self::assertStringContainsString('Render prerequisite evidence is unavailable; render certification is blocked.',$c);
 }
}
