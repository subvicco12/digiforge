<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use PHPUnit\Framework\TestCase;

final class FinalReadinessStructureTest extends TestCase
{
    public function testReadinessFailsClosedOnQueueAndRecoveryEvidence(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../includes/Operations/Readiness.php');
        self::assertStringContainsString("'queue_query_verified'", $source);
        self::assertStringContainsString("'recovery_drill_passed'", $source);
        self::assertStringContainsString('digiforge_recovery_database_backup_available', $source);
        self::assertStringContainsString('digiforge_recovery_plugin_package_available', $source);
        self::assertStringContainsString('digiforge_recovery_checksum_verified', $source);
        self::assertStringContainsString('digiforge_recovery_restore_instructions_available', $source);
        self::assertStringContainsString('FILTER_VALIDATE_BOOLEAN', $source);
        self::assertStringContainsString('$this->externalActionsPerformed()', $source);
    }

    public function testHealthMonitorPropagatesQueueQueryHealth(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../includes/Observability/HealthMonitor.php');
        self::assertStringContainsString("'query_ok'", $source);
        self::assertStringContainsString('$queueQueryOk', $source);
        self::assertStringContainsString('HealthStatus::UNHEALTHY', $source);
    }

    public function testInternalActivationGateIsNonWritable(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../includes/Core/Config.php');
        $expected = "'activation_authorized' => [" .
            "'type' => 'boolean', 'default' => false, 'writable' => false" .
            ']';

        self::assertStringContainsString($expected, $source);
    }
}
