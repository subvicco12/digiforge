<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class RecoveryEvidenceFailClosedContractTest extends TestCase {
 public function testRecoveryEvidenceCannotBeSynthesizedFromAvailabilityBooleans():void {
  $e=file_get_contents(__DIR__.'/../../includes/Operations/RecoveryEvidence.php');$r=file_get_contents(__DIR__.'/../../includes/Operations/Readiness.php');
  foreach(['identifier','captured_at','location','verification_method','verified_at','verified_by'] as $field)self::assertStringContainsString("'".$field."'",$e);
  self::assertStringContainsString('BACKUP_VERIFICATION_MAX_AGE_SECONDS = 86400',$e);
  self::assertStringContainsString("verification_status'] = 'VERIFIED'",$e);
  self::assertStringContainsString('RecoveryEvidence::snapshot()',$r);
  self::assertStringNotContainsString("optionEnabled('digiforge_recovery_database_backup_available')",$r);
  self::assertStringNotContainsString("optionEnabled('digiforge_recovery_plugin_package_available')",$r);
 }
 public function testRecoveryDrillRequiresEveryConcreteSafetyCondition():void {
  $review=DigiForge\Operations\RecoveryDrill::evaluate(['database_backup_available'=>true,'database_backup_retrievable'=>true]);
  self::assertSame('REVIEW_REQUIRED',$review['status']);self::assertFalse($review['external_actions_performed']);
  foreach(['database_backup_identity_recorded','plugin_package_available','plugin_package_retrievable','plugin_package_identity_recorded','checksum_verified','schema_version_known','restore_instructions_available','stop_all_confirmed'] as $key)self::assertFalse($review['checks'][$key]);
 }
}
