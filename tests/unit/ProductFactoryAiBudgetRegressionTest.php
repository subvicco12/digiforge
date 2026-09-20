<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use PHPUnit\Framework\TestCase;

final class ProductFactoryAiBudgetRegressionTest extends TestCase
{
    public function testProductionManifestReceivesFullStructuredOutputBudget(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../includes/Launch/OpenAIClient.php');
        self::assertStringContainsString("str_contains(\$brief, 'production-ready DigiForge asset manifest')", $source);
        self::assertStringContainsString('$productionManifest ? 16000 : 8000', $source);
    }

    public function testProductFactoryPromptMatchesProductionBudgetClassifier(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../includes/ProductFactory/Orchestrator.php');
        self::assertStringContainsString('production-ready DigiForge asset manifest', $source);
        self::assertStringContainsString('Return compact JSON only', $source);
    }
}
