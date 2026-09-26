<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class RemainingScopedActivationStructureTest extends TestCase
{
    public function testRemainingExternalCapabilitiesRequireDedicatedAuthorization(): void
    {
        $config=(string)file_get_contents(dirname(__DIR__,2).'/includes/Core/Config.php');
        $settings=(string)file_get_contents(dirname(__DIR__,2).'/includes/Core/Settings.php');
        $controller=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/Controller.php');
        foreach(['etsy_publish','order_automation','gst_automation'] as $capability) {
            self::assertStringContainsString("'".$capability."_activation_authorized'", $config);
            self::assertStringContainsString("$switch !== '".$capability."'", $settings);
        }
        self::assertStringContainsString('activateEtsyPublish()', $settings);
        self::assertStringContainsString('activateOrderAutomation()', $settings);
        self::assertStringContainsString('activateGstAutomation()', $settings);
        self::assertStringContainsString("['etsy-publish' => 'etsy_publish', 'order-automation' => 'order_automation', 'gst-automation' => 'gst_automation']", $controller);
        self::assertStringContainsString("'external_actions_performed' => false", $controller);
        self::assertStringNotContainsString('wp_remote_', (string)file_get_contents(dirname(__DIR__,2).'/includes/Launch/RemainingActivationPreflight.php'));
    }
}
