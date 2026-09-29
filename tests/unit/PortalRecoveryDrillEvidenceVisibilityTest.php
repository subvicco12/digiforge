<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PortalRecoveryDrillEvidenceVisibilityTest extends TestCase {
 public function testAttentionPortalExposesBoundDrillEvidenceWithoutExecutionAuthority():void {
  $p=(string)file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(['recovery_drill_evidence','Drill evidence','Recovery drill evidence:','database_backup_identifier','plugin_package_identifier','cannot perform a drill or authorize external execution'] as $needle) self::assertStringContainsString($needle,$p);
  self::assertStringNotContainsString('/recovery/drill-run',$p);
 }
}