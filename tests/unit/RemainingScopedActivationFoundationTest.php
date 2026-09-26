<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RemainingScopedActivationFoundationTest extends TestCase
{
    public function testRemainingCapabilitiesHaveDedicatedFailClosedAuthorization(): void
    {
        $root=dirname(__DIR__,2);
        $config=(string)file_get_contents($root.'/includes/Core/Config.php');
        $settings=(string)file_get_contents($root.'/includes/Core/Settings.php');
        $controller=(string)file_get_contents($root.'/includes/REST/Controller.php');
        $preflight=(string)file_get_contents($root.'/includes/Launch/RemainingActivationPreflight.php');

        foreach ([
            'gelato_activation_authorized',
            'etsy_publish_activation_authorized',
            'order_automation_activation_authorized',
            'gst_automation_activation_authorized',
        ] as $gate) {
            self::assertStringContainsString($gate,$config);
            self::assertStringContainsString($gate,$settings);
        }

        foreach (['activateGelato','activateEtsyPublish','activateOrderAutomation','activateGstAutomation'] as $method) {
            self::assertStringContainsString($method,$settings);
        }

        self::assertStringContainsString("'/activations/(?P<capability>gelato|etsy-publish|order-automation|gst-automation)'",$controller);
        self::assertStringContainsString('RemainingActivationPreflight',$controller);
        self::assertStringContainsString("'external_actions_performed'=>false",$controller);
        self::assertStringContainsString('Logger::write',$controller);
        self::assertStringContainsString('Settings::revokeScopedAuthorization',$controller);
        self::assertStringContainsString('network_requests_performed',$preflight);
        self::assertStringContainsString('external_actions_performed',$preflight);
        self::assertStringNotContainsString('wp_remote_',$preflight);
        self::assertStringNotContainsString('curl_init',$preflight);
    }

    public function testDependencyOrderRemainsExplicit(): void
    {
        $preflight=(string)file_get_contents(dirname(__DIR__,2).'/includes/Launch/RemainingActivationPreflight.php');
        self::assertStringContainsString("'gelato' => ['requires' => 'product_development'",$preflight);
        self::assertStringContainsString("'etsy_publish' => ['requires' => 'etsy_draft'",$preflight);
        self::assertStringContainsString("'order_automation' => ['requires' => 'etsy_publish'",$preflight);
        self::assertStringContainsString("Settings::is_enabled('printify')===true || Settings::is_enabled('gelato')===true",$preflight);
        self::assertStringContainsString("'gst_automation' => ['requires' => 'order_automation'",$preflight);
    }
}
