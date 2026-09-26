<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RemainingScopedActivationStructureTest extends TestCase
{
    public function testRemainingExternalControlsRequireDedicatedAuthorization(): void
    {
        $config = file_get_contents(dirname(__DIR__, 2) . '/includes/Core/Config.php');
        $settings = file_get_contents(dirname(__DIR__, 2) . '/includes/Core/Settings.php');
        $controller = file_get_contents(dirname(__DIR__, 2) . '/includes/REST/Controller.php');
        foreach (['etsy_publish', 'order_automation', 'gst_automation'] as $capability) {
            self::assertStringContainsString("'{$capability}_activation_authorized'", $config);
            self::assertStringContainsString("'{$capability}_activation_authorized'", $settings);
            self::assertStringContainsString("'{$capability}'", $settings);
        }
        self::assertStringContainsString('activateEtsyPublish()', $controller);
        self::assertStringContainsString('activateOrderAutomation()', $controller);
        self::assertStringContainsString('activateGstAutomation()', $controller);
        self::assertStringContainsString("'external_actions_performed' => false", $controller);
    }

    public function testDependencyChainIsFailClosed(): void
    {
        $settings = file_get_contents(dirname(__DIR__, 2) . '/includes/Core/Settings.php');
        self::assertStringContainsString("'etsy_draft_activation_authorized'", $settings);
        self::assertStringContainsString("'printify_activation_authorized'", $settings);
        self::assertStringContainsString("'order_automation_activation_authorized'", $settings);
        self::assertStringNotContainsString("'gelato_activation_authorized'", $settings);
    }
}
