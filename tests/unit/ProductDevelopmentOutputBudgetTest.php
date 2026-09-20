<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ProductDevelopmentOutputBudgetTest extends TestCase
{
    public function test_development_has_bounded_expanded_budget(): void
    {
        $source=file_get_contents(__DIR__.'/../../includes/Launch/OpenAIClient.php');
        self::assertIsString($source);
        self::assertStringContainsString('$productionManifest ? 16000 : 8000', $source);
        self::assertStringNotContainsString('$productionManifest ? 16000 : 4000', $source);
    }
}
