<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class U3RepairCycleSafetyMarkerTest extends TestCase
{
    public function testRepairOrchestratorDoesNotInvokeExternalProviders(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/ProductFactory/Orchestrator.php');
        self::assertIsString($source);
        self::assertMatchesRegularExpression("/'external_actions'\s*=>\s*false/", $source);
        self::assertMatchesRegularExpression("/'external_actions_performed'\s*=>\s*false/", $source);
        self::assertStringNotContainsString('new Etsy', $source);
        self::assertStringNotContainsString('new Printify', $source);
        self::assertStringNotContainsString('new Gelato', $source);
    }
}
