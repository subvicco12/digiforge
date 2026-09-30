<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RecoveryVerificationEvidenceContractTest extends TestCase
{
    public function testVerificationReceiptIsImmutableDispatchBoundAndNotDrillPass(): void
    {
        $code=(string)file_get_contents(__DIR__.'/../../includes/Operations/RecoveryVerificationEvidence.php');
        foreach ([
            "('verified'] ?? false) !== true",
            'RecoveryOrchestrator::snapshot()',
            "'reconciliation_required'] ?? false",
            "'target_environment'] ?? '') !== 'staging'",
            "'provider'] ?? '') !== 'hostinger'",
            'target_site_url',
            'database_backup_identifier',
            'plugin_package_identifier',
            'artifact_evidence_hash',
            'provider_operation_reference',
            'RecoveryDispatchLedger::read',
            'RecoveryDispatchLedger::insert',
            'digiforge_recovery_verification_conflict',
            "'drill_evidence_recorded' => false",
            "'commerce_execution_authorized' => false",
        ] as $needle) self::assertStringContainsString($needle,$code);
        self::assertStringNotContainsString('RecoveryDrillEvidence::store', $code);
        self::assertStringNotContainsString("'passed' => true", $code);
        self::assertStringNotContainsString('wp_remote_', $code);
    }

    public function testVerificationEndpointPersistsReceiptBeforeAudit(): void
    {
        $controller=(string)file_get_contents(__DIR__.'/../../includes/REST/Controller.php');
        self::assertStringContainsString('RecoveryVerificationEvidence::record($result)', $controller);
        self::assertStringContainsString("\$result['verification_evidence'] = \$evidence", $controller);
    }
}
