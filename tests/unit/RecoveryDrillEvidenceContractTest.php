<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RecoveryDrillEvidenceContractTest extends TestCase
{
    public function testReadinessRequiresConcreteDrillEvidence(): void
    {
        $readiness = file_get_contents(__DIR__.'/../../includes/Operations/Readiness.php');
        self::assertStringContainsString('RecoveryDrillEvidence::snapshot()', $readiness);
        self::assertStringContainsString("'recovery_drill_passed' => (\$recovery['status'] ?? '') === 'PASS' && (\$drillEvidence['passed'] ?? false) === true", $readiness);
        self::assertStringContainsString("'recovery_drill_evidence' => \$drillEvidence", $readiness);
    }

    public function testDrillEvidenceIsBoundFreshAndNonAuthorizing(): void
    {
        $evidence = file_get_contents(__DIR__.'/../../includes/Operations/RecoveryDrillEvidence.php');
        self::assertStringContainsString("'database_backup_identifier'", $evidence);
        self::assertStringContainsString("'plugin_package_identifier'", $evidence);
        self::assertStringContainsString("'restore_verified'", $evidence);
        self::assertStringContainsString("'schema_verified'", $evidence);
        self::assertStringContainsString("'application_health_verified'", $evidence);
        self::assertStringContainsString('MAX_AGE_SECONDS = 86400', $evidence);
        self::assertStringContainsString("'external_execution_authorized' => false", $evidence);
        self::assertStringNotContainsString('Settings::set', $evidence);
    }

    public function testManagementApiCannotPerformARecoveryDrill(): void
    {
        $controller = file_get_contents(__DIR__.'/../../includes/REST/Controller.php');
        self::assertStringContainsString("'/recovery/drill-evidence'", $controller);
        self::assertStringContainsString('RecoveryDrillEvidence::store', $controller);
        self::assertStringContainsString("'external_execution_authorized' => false", $controller);
        self::assertStringNotContainsString("'/recovery/drill-run'", $controller);
    }
}
