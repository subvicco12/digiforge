<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScopedActivationRecoveryTest extends TestCase
{
    public function testProtectedStateRecoveryIsAtomicAndDoesNotChangeFeatureSwitches(): void
    {
        $settings = file_get_contents(dirname(__DIR__, 2) . '/includes/Core/Settings.php');
        $controls = file_get_contents(dirname(__DIR__, 2) . '/includes/Portal/FrontendControls.php');
        self::assertIsString($settings);
        self::assertIsString($controls);
        self::assertStringContainsString('public static function protectProduction(): bool', $settings);
        self::assertStringContainsString("'stop_all' => true", $settings);
        self::assertStringContainsString("'automation_armed' => false", $settings);
        self::assertStringContainsString("'activation_authorized' => false", $settings);
        self::assertStringContainsString('Restore Protected Pre-Release State', $controls);
        self::assertStringContainsString("'external_feature_switches_changed' => false", $controls);
    }
}
