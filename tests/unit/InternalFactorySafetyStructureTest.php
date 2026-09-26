<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class InternalFactorySafetyStructureTest extends TestCase
{
    public function test_internal_factory_has_narrow_lock_bypass_only_for_ai_and_product_development(): void
    {
        $settings = file_get_contents(__DIR__ . '/../../includes/Core/Settings.php');
        $engine = file_get_contents(__DIR__ . '/../../includes/Launch/ExecutionEngine.php');

        self::assertStringContainsString("public static function is_internal_enabled", $settings);
        self::assertStringContainsString("['ai', 'product_development']", $settings);
        self::assertStringContainsString("Settings::is_enabled('ai')", $engine);
        self::assertStringContainsString("Settings::is_enabled('product_development')", $engine);
        self::assertStringContainsString("Settings::is_enabled('research')", $engine);
        self::assertStringNotContainsString("is_internal_enabled('etsy_publish')", $engine);
        self::assertStringNotContainsString("is_internal_enabled('printify')", $engine);
        self::assertStringNotContainsString("is_internal_enabled('gelato')", $engine);
        self::assertStringNotContainsString("is_internal_enabled('order_automation')", $engine);
        self::assertStringNotContainsString("is_internal_enabled('gst_automation')", $engine);
    }
}
