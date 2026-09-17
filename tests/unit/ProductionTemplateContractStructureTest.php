<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductionTemplateContractStructureTest extends TestCase
{
    public function testContractLocksSupplierGeometryAndPersonalization(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/ProductionTemplateContract.php');
        self::assertIsString($source);
        self::assertStringContainsString('provider_blueprint_id',$source);
        self::assertStringContainsString('provider_id',$source);
        self::assertStringContainsString('variant_ids',$source);
        self::assertStringContainsString('print_areas',$source);
        self::assertStringContainsString('width_px',$source);
        self::assertStringContainsString('height_px',$source);
        self::assertStringContainsString('decoration_method',$source);
        self::assertStringContainsString('PRINTIFY_NATIVE',$source);
        self::assertStringContainsString('DIGIFORGE_RENDER',$source);
        self::assertStringContainsString('DIGIFORGE_AI',$source);
        self::assertStringContainsString("hash('sha256'",$source);
    }

    public function testValidatedTemplatesAreImmutableAndContractHasNoExecution(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/ProductionTemplateContract.php');
        self::assertStringContainsString("['VALIDATED','RETIRED']",$source);
        self::assertStringContainsString('catalog drift creates a new candidate version',$source);
        self::assertStringNotContainsString('wp_remote_',$source);
        self::assertStringNotContainsString('createOrder',$source);
        self::assertStringNotContainsString('publish',$source);
    }
}
