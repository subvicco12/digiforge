<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class ProductFactorySemanticEvidenceContractTest extends TestCase
{
    public function test_manifest_contract_addresses_live_semantic_qa_failures(): void
    {
        $source=(string)file_get_contents(__DIR__.'/../../includes/ProductFactory/Orchestrator.php');
        self::assertStringContainsString('Filenames are flat local filenames', $source);
        self::assertStringContainsString('complete translated customer-facing prose', $source);
        self::assertStringContainsString('Optional link, QR, map, RSVP, registry, hotel or transport fields must be omitted everywhere unless a verified destination exists', $source);
        self::assertStringContainsString('LICENSE-AND-PROVENANCE.txt', $source);
        self::assertStringContainsString('no third-party artwork is bundled', $source);
        self::assertStringContainsString('customer package ZIP must contain product assets only', $source);
        self::assertStringContainsString('marketing_assets_included=false', $source);
        self::assertStringContainsString('Marketing assets must not be passed into customer package construction', $source);
    }
}
