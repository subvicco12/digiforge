<?php
declare(strict_types=1);

final class PluginActivationTest extends WP_UnitTestCase
{
    public function testPluginBootsWithAutomationFailClosed(): void
    {
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
        self::assertFalse(DigiForge\Core\Settings::is_enabled('etsy_publish'));
        self::assertFalse(DigiForge\Core\Settings::is_enabled('order_automation'));
    }
}
