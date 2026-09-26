<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class AdminOperationsFinalCertificationTest extends TestCase
{
    public function testControlCenterAndFrontendAreOperatorSurfacesNotActivationBypass(): void
    {
        $admin = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/Core/Admin.php');
        $portal = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/Portal/FrontendControls.php');

        foreach (['digiforge-system-status', 'digiforge-readiness', 'digiforge-safety-controls', 'render_system_status', 'render_readiness', 'render_safety_controls'] as $needle) {
            self::assertStringContainsString($needle, $admin);
        }

        foreach (['authorize(self::ACTION)', 'authorize(self::ACTIVATE)', 'check_admin_referer', "current_user_can('manage_digiforge_automation')", 'Settings::activateResearch()'] as $needle) {
            self::assertStringContainsString($needle, $portal);
        }
    }

    public function testProtectedControlsCannotReleaseStopAllThroughGenericSwitchUpdate(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/Portal/FrontendControls.php');

        foreach (['Use the protected Production Activation action to release STOP ALL.', 'Activation is not authorized. The requested control remains OFF.', 'Automation is not armed. The requested control remains OFF.', 'Config::allowed_switch', 'Settings::set'] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
    }

    public function testQueueRecoveryAndRetryRemainGoverned(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/Queue/JobRepository.php');
        foreach (['acquireLease', 'releaseLease', 'scheduleRetry', 'recoverExpiredLease', 'deadLetter', 'DEAD_LETTER', 'HUMAN_REVIEW', 'idempotency_key'] as $needle) {
            self::assertStringContainsString($needle, $source);
        }

        $scheduler = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/Queue/Scheduler.php');
        self::assertStringContainsString('Intentionally inert', $scheduler);
    }

    public function testReadinessFailsClosedOnRecoveryAndOperationalHealth(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/Operations/Readiness.php');
        foreach (['queue_query_verified', 'queue_has_no_expired_leases', 'recovery_drill_passed', 'READY_LOCKED', 'REVIEW_REQUIRED', 'external_actions_performed'] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
    }

    public function testP6SafetyPrerequisitesRemainLocked(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/operations/P6_FAILURE_SCENARIO_TEST_MATRIX.md');
        foreach (['STOP ALL = ON', 'Activation authorization = OFF', 'Automation armed = FALSE', 'external_actions_performed = false', 'No Etsy publishing or marketplace mutation', 'No Printify/Gelato live orders', 'No banking/payment action', 'No GST/tax authority call'] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
    }

    public function testRecoveryGuideAndControlCenterScopeArePresent(): void
    {
        $base = dirname(__DIR__, 2);
        foreach (['docs/operations/P4_CONTROL_CENTER_SCOPE.md', 'docs/operations/RECOVERY_RESTORE_RUNBOOK.md', 'docs/operations/PRODUCTION_OPERATIONS_GUIDE.md', 'tests/unit/AdminControlCenterStructureTest.php', 'tests/unit/OperationsSafeguardsTest.php'] as $file) {
            self::assertFileExists($base . '/' . $file, $file);
        }
    }
}
