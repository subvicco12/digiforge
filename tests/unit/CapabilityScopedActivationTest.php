<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class CapabilityScopedActivationTest extends TestCase
{
    public function testResearchActivationIsCapabilityScopedAndLegacyGlobalReleaseFailsClosed(): void
    {
        $config=(string)file_get_contents(dirname(__DIR__,2).'/includes/Core/Config.php');
        $settings=(string)file_get_contents(dirname(__DIR__,2).'/includes/Core/Settings.php');
        $controls=(string)file_get_contents(dirname(__DIR__,2).'/includes/Portal/FrontendControls.php');
        self::assertStringContainsString("'research_activation_authorized'", $config);
        self::assertStringContainsString('public static function activateResearch(): bool', $settings);
        self::assertStringContainsString("'research_activation_authorized' => true", $settings);
        self::assertStringContainsString("in_array(\$switch, ['research', 'ai', 'product_development', 'etsy_draft', 'printify'], true)", $settings);
        self::assertStringContainsString("'research_activation_authorized', false", $settings);
        self::assertStringContainsString('public static function activateProduction(): bool', $settings);
        self::assertMatchesRegularExpression('/activateProduction\(\): bool\s*\{\s*\/\/.*?\s*return false;/s', $settings);
        self::assertStringContainsString('Settings::activateResearch()', $controls);
        self::assertStringContainsString('Authorize Research &amp; Release STOP ALL', $controls);
        self::assertStringContainsString("'capability' => 'research'", $controls);
    }
}
