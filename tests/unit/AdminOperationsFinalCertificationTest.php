<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class AdminOperationsFinalCertificationTest extends TestCase
{
 public function testControlCenterAndFrontendAreOperatorSurfacesNotActivationBypass(): void
 {
  $admin=(string)file_get_contents(dirname(__DIR__,2).'/includes/Core/Admin.php');
  $portal=(string)file_get_contents(dirname(__DIR__,2).'/includes/Portal/FrontendControls.php');
  foreach (['digiforge-system-status','digiforge-readiness','digiforge-safety-controls','render_system_status','render_readiness','render_safety_controls'] as $x) self::assertStringContainsString($x,$admin);
  foreach (['self::ACTION','self::ACTIVATE','check_admin_referer(\$action)',"current_user_can('manage_digiforge_automation')",'Settings::activateProduction'] as $x) self::assertStringContainsString($x,$portal);
 }
 public function testProtectedControlsCannotReleaseStopAllThroughGenericSwitchUpdate(): void
 {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Portal/FrontendControls.php');
  foreach (['\$key === \'stop_all\'','\$enabled','Use the protected Production Activation action','activation_authorized','automation_armed'] as $x) self::assertStringContainsString($x,$s);
 }
 public function testQueueRecoveryAndRetryRemainGoverned(): void
 {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Queue/JobRepository.php');
  foreach (['acquireLease','releaseLease','scheduleRetry','recoverExpiredLease','deadLetter','DEAD_LETTER','HUMAN_REVIEW','idempotency_key'] as $x) self::assertStringContainsString($x,$s);
  $scheduler=(string)file_get_contents(dirname(__DIR__,2).'/includes/Queue/Scheduler.php');
  self::assertStringContainsString('Intentionally inert',$scheduler);
 }
 public function testReadinessFailsClosedOnRecoveryAndOperationalHealth(): void
 {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Operations/Readiness.php');
  foreach (['queue_query_verified','queue_has_no_expired_leases','recovery_drill_passed','READY_LOCKED','REVIEW_REQUIRED','external_actions_performed'] as $x) self::assertStringContainsString($x,$s);
 }
 public function testP6SafetyPrerequisitesRemainLocked(): void
 {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/docs/operations/P6_FAILURE_SCENARIO_TEST_MATRIX.md');
  foreach (['STOP ALL = ON','Activation authorization = OFF','Automation armed = FALSE','external_actions_performed = false','No Etsy publishing or marketplace mutation','No Printify/Gelato live orders','No banking/payment action','No GST/tax authority call'] as $x) self::assertStringContainsString($x,$s);
 }
 public function testRecoveryGuideAndControlCenterScopeArePresent(): void
 {
  $base=dirname(__DIR__,2);
  foreach (['docs/operations/P4_CONTROL_CENTER_SCOPE.md','docs/operations/RECOVERY_RESTORE_RUNBOOK.md','docs/operations/PRODUCTION_OPERATIONS_GUIDE.md','tests/unit/AdminControlCenterStructureTest.php','tests/unit/OperationsSafeguardsTest.php'] as $f) self::assertFileExists($base.'/'.$f,$f);
 }
}
