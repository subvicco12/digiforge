<?php
declare(strict_types=1);

use DigiForge\Launch\ProductDevelopmentActivationPreflight;
use PHPUnit\Framework\TestCase;

final class ProductDevelopmentScopedActivationTest extends TestCase
{
    public function testStageThreePreflightRequiresAiAndKeepsLaterStagesOut(): void
    {
        $ready = ProductDevelopmentActivationPreflight::summarize([
            'stop_all'=>false,'activation_authorized'=>true,'automation_armed'=>true,
            'research_effective'=>true,'ai_effective'=>true,'product_development_configured'=>true,
            'product_development_authorized'=>false,'product_development_effective'=>false,
        ]);
        self::assertSame('READY_FOR_CONTROLLED_PRODUCT_DEVELOPMENT_ACTIVATION', $ready['status']);
        self::assertFalse($ready['network_requests_performed']);
        self::assertFalse($ready['external_actions_performed']);

        $blocked = ProductDevelopmentActivationPreflight::summarize([
            'stop_all'=>false,'activation_authorized'=>true,'automation_armed'=>true,
            'research_effective'=>true,'ai_effective'=>false,'product_development_configured'=>true,
            'product_development_authorized'=>false,'product_development_effective'=>false,
        ]);
        self::assertSame('BLOCKED', $blocked['status']);
        self::assertContains('ai_effective', $blocked['blockers']);

        $settings=file_get_contents(dirname(__DIR__,2).'/includes/Core/Settings.php');
        self::assertIsString($settings);
        self::assertStringContainsString("in_array(\$switch, ['research', 'ai', 'product_development', 'etsy_draft', 'printify', 'gelato', 'etsy_publish', 'order_automation', 'gst_automation'], true)", $settings);
        self::assertStringContainsString("product_development_activation_authorized", $settings);
        self::assertStringContainsString("(\$switch !== 'printify' || self::get('printify_activation_authorized', false) === true)", $settings);

        $portal=file_get_contents(dirname(__DIR__,2).'/includes/Portal/FrontendControls.php');
        self::assertIsString($portal);
        self::assertStringContainsString('Authorize Product Development', $portal);
        self::assertStringContainsString('Printify, Gelato, Etsy, orders, and GST remain ineffective.', $portal);
    }
}
