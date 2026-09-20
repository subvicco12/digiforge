<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class ProductFactoryManifestBudgetTest extends TestCase
{
    public function test_manifest_prompt_is_bounded_without_weakening_deliverables(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/ProductFactory/Orchestrator.php');
        self::assertStringContainsString('Keep the complete response comfortably below the provider output limit', $source);
        self::assertStringContainsString('generate the minimum sellable asset set', $source);
        self::assertStringContainsString('avoid duplicate content across files', $source);
        self::assertStringContainsString('purpose and complete content', $source);
        self::assertStringContainsString('PDF deliverables must be emitted as format pdf', $source);
        self::assertStringContainsString('Marketing must describe only what the generated package really contains', $source);
    }
}
