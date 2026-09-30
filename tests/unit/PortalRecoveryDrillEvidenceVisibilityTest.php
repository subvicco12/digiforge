<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PortalRecoveryDrillEvidenceVisibilityTest extends TestCase {
 public function testAttentionPortalExposesBoundDrillEvidenceWithoutExecutionAuthority():void {
  $p=(string)file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(['recovery_drill_evidence','Drill evidence','Recovery drill evidence:','database_backup_identifier','plugin_package_identifier','Governed recovery orchestration'] as $needle) self::assertStringContainsString($needle,$p);
  self::assertStringContainsString('Create recovery plan',$p);
  self::assertStringContainsString('Provider capability',$p);
  self::assertStringContainsString('Commerce authority</span><b>NO',$p);
  self::assertStringContainsString('External execution authority</span><b>NO',$p);
 }
}