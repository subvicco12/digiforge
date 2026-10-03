<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class MigratorLegacyCleanupDbEvidenceContractTest extends TestCase {
 public function testLegacyJobsEvidenceAndCleanupFailClosedBeforeSchemaAdvance():void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Database/Migrator.php');
  self::assertStringContainsString("'LEGACY_JOBS_TABLE_EVIDENCE_UNAVAILABLE'",$s);
  self::assertStringContainsString("'LEGACY_JOBS_IDEMPOTENCY_CLEANUP_FAILED'",$s);
  self::assertStringContainsString("\$cleanupResult === false || \$wpdb->last_error !== ''",$s);
  $lookup=strpos($s,'LEGACY_JOBS_TABLE_EVIDENCE_UNAVAILABLE');
  $advance=strpos($s,"update_option('digiforge_db_schema_version'");
  self::assertNotFalse($lookup);self::assertNotFalse($advance);self::assertLessThan($advance,$lookup);
 }
}