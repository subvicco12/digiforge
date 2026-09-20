<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class ProductFactoryFeasibleSpecificationTest extends TestCase
{
    public function test_development_and_production_share_local_capability_contract(): void
    {
        $engine = file_get_contents(__DIR__ . '/../../includes/Launch/ExecutionEngine.php');
        $factory = file_get_contents(__DIR__ . '/../../includes/ProductFactory/Orchestrator.php');
        $producer = file_get_contents(__DIR__ . '/../../includes/ProductFactory/LocalAssetProducer.php');

        self::assertStringContainsString('only local html, txt, json, csv, svg, pdf and zip assets', $engine);
        self::assertStringContainsString('Do not require Canva templates', $engine);
        self::assertStringContainsString('unless a real verified destination URL is already present', $engine);
        self::assertStringContainsString('Every listing title, description, feature, variant and buyer promise must be backed by an asset requirement', $engine);
        self::assertStringContainsString('format (html/txt/json/csv/svg/pdf)', $factory);
        self::assertStringContainsString("'svg','pdf'", $factory);
        self::assertStringContainsString('PDF deliverables must be emitted as format pdf', $factory);
        self::assertStringContainsString("'txt','html','csv','json','svg','pdf'", $producer);
    }
}
