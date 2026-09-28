<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class OperatorPreflightEvidenceAvailabilityContractTest extends TestCase {
 public function testPackageReadFailureIsUnavailableNotMissing():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/ProductionPreflightOperatorReadModel.php');
  self::assertStringContainsString('production_preflight_evidence_unavailable',$c);
  self::assertStringContainsString('Production preflight operator evidence is unavailable; execution remains blocked.',$c);
  self::assertStringContainsString("!empty(\$wpdb->last_error)",$c);
 }
 public function testUnavailableProjectionCannotGrantExecution():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/ProductionPreflightOperatorReadModel.php');
  self::assertGreaterThanOrEqual(2,substr_count($c,"'external_execution_authorized'=>false"));
  self::assertGreaterThanOrEqual(2,substr_count($c,"'external_execution_performed'=>false"));
 }
}
