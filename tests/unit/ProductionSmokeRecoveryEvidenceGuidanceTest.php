<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ProductionSmokeRecoveryEvidenceGuidanceTest extends TestCase {
 public function testSmokePlanRequiresConcreteEvidenceAndRejectsLegacyAvailabilityFlag():void {
  $p=(string)file_get_contents(__DIR__.'/../../docs/testing/P5_PRODUCTION_SMOKE_TEST_PLAN.md');
  self::assertStringNotContainsString('digiforge_recovery_database_backup_available',$p);
  foreach(['identifier, capture time, location, retrievability, verification method, verification time and verifier identity','legacy availability booleans are not recovery evidence','exact current database-backup and rollback-package identifiers','restore, schema and application-health verification all true'] as $v) self::assertStringContainsString($v,$p);
 }
}