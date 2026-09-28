<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class LifecycleClosureEvidenceAvailabilityContractTest extends TestCase {
 public function testPrerequisiteAndConflictReadsFailClosed():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/ProductionLifecycleClosureRepository.php');
  self::assertGreaterThanOrEqual(4,substr_count($c,'production_closure_evidence_unavailable'));
  self::assertStringContainsString('Lifecycle closure evidence is unavailable; closure is blocked.',$c);
  self::assertStringContainsString("'retry_permitted'=>false",$c);
  self::assertStringContainsString("'external_execution_authorized'=>false",$c);
 }
 public function testUncertainInsertConfirmationForbidsRetry():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/ProductionLifecycleClosureRepository.php');
  self::assertStringContainsString('production_closure_confirmation_unavailable',$c);
  self::assertStringContainsString('Lifecycle closure may have persisted but confirmation is unavailable; do not retry.',$c);
 }
}
