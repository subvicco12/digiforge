<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ActivationAuditFailClosedTest extends TestCase
{
    public function testAllActivationSurfacesRevokeOrProtectWhenAuditPersistenceFails(): void
    {
        $root = dirname(__DIR__, 2);
        $settings = (string) file_get_contents($root . '/includes/Core/Settings.php');
        $portal = (string) file_get_contents($root . '/includes/Portal/FrontendControls.php');
        $controller = (string) file_get_contents($root . '/includes/REST/Controller.php');

        foreach ([
            'ai_activation_authorized',
            'product_development_activation_authorized',
            'etsy_draft_activation_authorized',
            'printify_activation_authorized',
            'gelato_activation_authorized',
            'etsy_publish_activation_authorized',
            'order_automation_activation_authorized',
            'gst_automation_activation_authorized',
        ] as $gate) {
            self::assertStringContainsString("'" . $gate . "'", $settings);
        }

        self::assertStringContainsString("Logger::write('research_activation_authorized'", $portal);
        self::assertStringContainsString('Settings::protectProduction();', $portal);
        self::assertStringContainsString("Logger::write('ai_activation_authorized'", $portal);
        self::assertStringContainsString("Settings::revokeScopedAuthorization('ai_activation_authorized')", $portal);
        self::assertStringContainsString("Logger::write('product_development_activation_authorized'", $portal);
        self::assertStringContainsString("Settings::revokeScopedAuthorization('product_development_activation_authorized')", $portal);

        self::assertStringContainsString("Logger::write('printify_activation_authorized'", $controller);
        self::assertStringContainsString("Settings::revokeScopedAuthorization('printify_activation_authorized')", $controller);
        self::assertStringContainsString("Logger::write('etsy_draft_activation_authorized'", $controller);
        self::assertStringContainsString("Settings::revokeScopedAuthorization('etsy_draft_activation_authorized')", $controller);
        self::assertStringContainsString('Settings::revokeScopedAuthorization($capability', $controller);
    }
}
