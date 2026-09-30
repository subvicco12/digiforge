<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class FinalReleaseConvergenceContractTest extends TestCase {
 public function testCoreClosureGuardrailsArePresentOnCombinedMain():void {
  $root=dirname(__DIR__,2);
  foreach([
   'tests/unit/RecoveryPlanBackupIdentityReferenceContractTest.php',
   'tests/unit/PersonalizedPodClosureSafetyContractTest.php',
   'tests/unit/BlueprintFinalPortalAcceptanceContractTest.php',
   'tests/unit/PortalRecoveryReviewControlsContractTest.php'
  ] as $path) self::assertFileExists($root.'/'.$path);
 }
 public function testFinalReleaseStillFailsClosedAcrossRecoveryAndPod():void {
  $root=dirname(__DIR__,2);
  $recovery=(string)file_get_contents($root.'/includes/Operations/RecoveryOrchestrator.php');
  $pod=(string)file_get_contents($root.'/includes/POD/ProductionAuthorizationRepository.php');
  foreach(['backup_identity_operation_key','backup_identity_binding_hash','digiforge_recovery_backup_identity_unbound'] as $needle) self::assertStringContainsString($needle,$recovery);
  foreach(["external_execution_authorized'=>0","external_execution_performed'=>0","state'=>'APPROVED_PACKAGE'"] as $needle) self::assertStringContainsString($needle,$pod);
 }
 public function testSchemaRemainsStableForClosureRelease():void {
  $plugin=(string)file_get_contents(dirname(__DIR__,2).'/digiforge.php');
  self::assertStringContainsString("const DIGIFORGE_DB_VERSION = '23';",$plugin);
 }
}
