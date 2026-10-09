<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RecoveryStagingVerifierContractTest extends TestCase
{
    public function testVerifierIsReadOnlyFailClosedAndRequiresLockedStagingEvidence(): void
    {
        $code=(string)file_get_contents(__DIR__.'/../../includes/Operations/RecoveryStagingVerifier.php');
        foreach ([
            'RecoveryOrchestrator::snapshot()',
            'RecoveryOrchestrator::safetyLocked()',
            "'target_environment'] ?? '') !== 'staging'",
            "'provider'] ?? '') !== 'hostinger'",
            'RecoveryStagingClient',
            'RecoveryOrchestrator::plannedPackageVersion($plan)',
            "'health_ok'",
            "'stop_all_active'",
            "'externally_locked'",
            "'schema_current'",
            "'plugin_version_exact'",
            "'automation_disabled'",
            "'activation_not_authorized'",
            "'automation_unarmed'",
            "'no_effective_feature_switches'",
            "'drill_evidence_recorded' => false",
            "'commerce_execution_authorized' => false",
        ] as $needle) self::assertStringContainsString($needle,$code);
        self::assertStringContainsString("!== 'digiforgestaging.converentis.com'", $code);
        $orchestrator=(string)file_get_contents(__DIR__.'/../../includes/Operations/RecoveryOrchestrator.php');
        self::assertStringContainsString('canonicalStagingTarget($targetSiteUrl)', $orchestrator);
        self::assertStringContainsString("=== 'digiforgestaging.converentis.com'", $orchestrator);
        self::assertGreaterThanOrEqual(3, substr_count($orchestrator, 'canonicalStagingTarget('));
        self::assertStringNotContainsString('RecoveryEvidence::snapshot()', $code);
        self::assertStringNotContainsString('RecoveryDrillEvidence::store', $code);
        self::assertStringNotContainsString('RecoveryOrchestrator::execute', $code);
        self::assertStringNotContainsString('wp_remote_post(', $code);
        self::assertStringNotContainsString('wp_remote_get(', $code);

        $client=(string)file_get_contents(__DIR__.'/../../includes/Integrations/RecoveryStagingClient.php');
        self::assertStringContainsString('wp_remote_get(', $client);
        self::assertStringContainsString("'redirection' => 0", $client);
        self::assertStringContainsString("'reject_unsafe_urls' => true", $client);
        self::assertStringContainsString("'sslverify' => true", $client);
        self::assertStringContainsString("'Accept' => 'application/json'", $client);
        self::assertStringContainsString("'User-Agent' => 'DigiForge-Recovery-Verifier/", $client);
        self::assertStringContainsString("'upstream_status'", $client);
        self::assertStringNotContainsString('wp_remote_post(', $client);
        self::assertStringNotContainsString("'passed' => true", $code);
    }

    public function testManagementApiExposesSeparateVerificationOperation(): void
    {
        $controller=(string)file_get_contents(__DIR__.'/../../includes/REST/Controller.php');
        self::assertStringContainsString("'/recovery/orchestration/verify'", $controller);
        self::assertStringContainsString('RecoveryStagingVerifier::verify', $controller);
        self::assertStringContainsString('recovery_orchestration_staging_verified', $controller);
    }
}
