<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Stage3WorkerEffectiveGateTest extends TestCase
{
    public function testResumableWorkerRequiresEffectiveAiAndProductDevelopment(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/ProductFactory/ApprovalAutomation.php');
        self::assertStringContainsString("Settings::is_enabled('ai')", $source);
        self::assertStringContainsString("Settings::is_enabled('product_development')", $source);
        self::assertStringContainsString("'reason'=>'stage3_not_effective'", $source);
        self::assertStringContainsString("'external_actions'=>false", $source);

        $gate = strpos($source, "Settings::is_enabled('product_development')");
        $providerStart = strpos($source, 'startBackgroundDevelop(');
        self::assertNotFalse($gate);
        self::assertNotFalse($providerStart);
        self::assertLessThan($providerStart, $gate, 'Effective Stage 3 gate must execute before background provider work.');
    }
}
