<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RecoveryOrchestrationContractTest extends TestCase
{
    public function testOrchestratorIsFailClosedAndArtifactBound(): void
    {
        $code = (string) file_get_contents(__DIR__.'/../../includes/Operations/RecoveryOrchestrator.php');
        foreach ([
            "Settings::safety_locked()",
            "Settings::get('stop_all', true)",
            "target_environment",
            "target_site_url",
            "database_backup_identifier",
            "plugin_package_identifier",
            "plugin_package_version",
            "plugin_package_source_commit",
            "plugin_package_sha256",
            "database_backup_retrievable",
            "plugin_package_retrievable",
            "checksum_verified",
            "digiforge_recovery_target_production",
            "digiforge_recovery_idempotency_conflict",
            "digiforge_recovery_provider_required",
            "commerce_execution_authorized' => false",
            "provider_operation_reference",
            "reconciliation_required",
            "digiforge_recovery_execution_persist_failed",
        ] as $needle) {
            self::assertStringContainsString($needle, $code);
        }
        self::assertStringContainsString('plannedPackageVersion', $code);
        self::assertStringContainsString("preg_match('/(?:^|-)v", $code);
        self::assertStringNotContainsString('Settings::set(', $code);
        self::assertStringNotContainsString('shell_exec(', $code);
        self::assertStringNotContainsString('exec(', $code);
        self::assertStringNotContainsString('$wpdb->query(', $code);
    }

    public function testManagementApiOnlyDispatchesToGovernedProviderAdapter(): void
    {
        $controller = (string) file_get_contents(__DIR__.'/../../includes/REST/Controller.php');
        self::assertStringContainsString("'/recovery/orchestration'", $controller);
        self::assertStringContainsString("'/recovery/orchestration/plan'", $controller);
        self::assertStringContainsString("'/recovery/orchestration/execute'", $controller);
        self::assertStringContainsString('RecoveryOrchestrator::plan', $controller);
        self::assertStringContainsString('RecoveryOrchestrator::execute', $controller);
        self::assertStringContainsString('recovery_orchestration_provider_dispatched', $controller);
    }

    public function testProviderDispatchCannotCreateVerifiedDrillEvidence(): void
    {
        $code = (string) file_get_contents(__DIR__.'/../../includes/Operations/RecoveryOrchestrator.php');
        self::assertStringNotContainsString('RecoveryDrillEvidence::store', $code);
        self::assertStringNotContainsString("'passed' => true", $code);
        self::assertStringContainsString("'VERIFY_REQUIRED'", $code);
    }
}
