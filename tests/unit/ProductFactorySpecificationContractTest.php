<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductFactorySpecificationContractTest extends TestCase
{
    public function test_production_prompt_enforces_deliverable_and_marketing_consistency(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/ProductFactory/Orchestrator.php');

        self::assertStringContainsString('binding production contract', $source);
        self::assertStringContainsString('Do not silently substitute a different deliverable type', $source);
        self::assertStringContainsString('PDF deliverables may not be represented or marketed as PDFs when only HTML is produced', $source);
        self::assertStringContainsString('may be claimed only when a real approved destination is available', $source);
        self::assertStringContainsString('Preserve the approved language editions and variant structure exactly', $source);
        self::assertStringContainsString('Marketing must describe only what the generated package really contains', $source);
        self::assertStringContainsString('fake Canva links', $source);
        self::assertStringContainsString("'external_publish'=>false", $source);
    }
}
