<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class RecoveryEvidenceStatusDrilldownContractTest extends TestCase {
 public function testCurrentStatusAndHistoryAreBoundedReadOnlyNonAuthorizing():void {
  $c=file_get_contents(__DIR__.'/../../includes/Operations/RecoveryEvidence.php');
  foreach(["'MISSING'","'VERIFIED'","'STALE'","min(200, \$limit)","'read_only' => true","'retry_permitted' => false","'external_execution_authorized' => false"] as $v) self::assertStringContainsString($v,$c);
  self::assertStringContainsString("'database_backup_status' => self::backupStatus(\$backup)",$c);
  self::assertStringContainsString("'database_backup_history' => self::recentBackupHistory(25)",$c);
 }
 public function testPortalLabelsHistoryAsReadOnlyAndNonAuthorizing():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  self::assertStringContainsString('most recent evidence record(s) available read-only',$c);
  self::assertStringContainsString('history never grants retry or execution authority',$c);
 }
}