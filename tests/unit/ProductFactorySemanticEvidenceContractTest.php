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
        self::assertStringContainsString('marketing_assets_in_customer_package=false', $source);
        self::assertStringContainsString('Marketing assets must not be passed into customer package construction', $source);
        self::assertStringContainsString("'generation_timestamp'=>gmdate('c')", $source);
        self::assertStringContainsString("'translation_review_status'=>'Only reviewed-language customer files are eligible for delivery'", $source);
        self::assertStringContainsString('one complete SVG source for every promised guide page, language and paper-size variant', $source);
    }
    public function testAction54CompletenessContractIsExplicit(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../includes/ProductFactory/Orchestrator.php');
        self::assertStringContainsString('Preserve every required filename and extension from the approved specification exactly', $source);
        self::assertStringContainsString('COMPLETE promised section set', $source);
        self::assertStringContainsString('[[PAGE_BREAK]]', $source);
        self::assertStringContainsString('Marketing copy may claim only features', $source);
    }
}
