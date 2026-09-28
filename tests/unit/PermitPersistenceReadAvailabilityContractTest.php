<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PermitPersistenceReadAvailabilityContractTest extends TestCase {
 public function testFailedPersistenceReadsAreUnavailableNotOrdinaryUnknown():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/ProductionPermitPersistenceReadModel.php');
  self::assertGreaterThanOrEqual(2,substr_count($c,"'persistence_state'=>'EVIDENCE_UNAVAILABLE'"));
  self::assertGreaterThanOrEqual(2,substr_count($c,'!empty($wpdb->last_error)'));
  self::assertStringContainsString("'retry_permitted'=>false",$c);
  self::assertStringContainsString("'external_execution_authorized'=>false",$c);
  self::assertStringContainsString("'read_only'=>true",$c);
 }
}
