<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class RecoveryBackupEvidenceHistoryContractTest extends TestCase {
 public function testVerifiedBackupHistoryIsPreservedBeforeCurrentProjectionUpdates():void {
  $c=file_get_contents(__DIR__.'/../../includes/Operations/RecoveryEvidence.php');
  self::assertStringContainsString('digiforge_recovery_database_backup_evidence_history',$c);
  self::assertStringContainsString("'evidence_hash'",$c);
  self::assertStringContainsString('appendBackupHistory($normalized)',$c);
  self::assertLessThan(strpos($c,"update_option('digiforge_recovery_database_backup_evidence', \$normalized"),strpos($c,'appendBackupHistory($normalized)'));
 }
 public function testPortalRequiresActualReverificationNotTimestampRefresh():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  self::assertStringContainsString('verification expires after 24 hours',$c);
  self::assertStringContainsString('do not merely refresh the timestamp',$c);
 }
}