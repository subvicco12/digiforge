<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use PHPUnit\Framework\TestCase;

final class AdminControlCenterStructureTest extends TestCase
{
    public function testCoreAdminContainsCentralOperationalSurfaces(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../includes/Core/Admin.php');

        self::assertStringContainsString('DigiForge Control Center', $source);
        self::assertStringContainsString('digiforge-system-status', $source);
        self::assertStringContainsString('digiforge-readiness', $source);
        self::assertStringContainsString('digiforge-safety-controls', $source);
        self::assertStringContainsString('HealthMonitor', $source);
        self::assertStringContainsString('evidence_hash', $source);
        self::assertStringContainsString('external_actions_performed', $source);
        self::assertStringContainsString('effective_switches', $source);
    }

    public function testSafetyPageDoesNotExposeActivationMutationControls(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../includes/Core/Admin.php');

        self::assertStringContainsString('This screen intentionally provides no activation controls.', $source);
        self::assertStringNotContainsString("Settings::set('automation_armed'", $source);
        self::assertStringNotContainsString("Settings::set('activation_authorized'", $source);
        self::assertStringNotContainsString("Settings::set('stop_all'", $source);
    }
}
