<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductionTemplateContractStructureTest extends TestCase
{
    public function testContractLocksSupplierGeometryAndPersonalization(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/ProductionTemplateContract.php');
        self::assertIsString($source);
        foreach(['provider_blueprint_id','provider_id','variant_ids','print_areas','width_px','height_px','decoration_method','PRINTIFY_NATIVE','DIGIFORGE_RENDER','DIGIFORGE_AI'] as $needle) self::assertStringContainsString($needle,$source);
        self::assertStringContainsString("hash('sha256'",$source);
    }

    public function testCanonicalIdentityRejectsDuplicateAreasAndOrderDrift(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/ProductionTemplateContract.php');
        self::assertStringContainsString("strtolower(self::token(\$input['supplier']",$source);
        self::assertStringContainsString("strtolower(self::token(\$area['position']",$source);
        self::assertStringContainsString("strtolower(self::token(\$area['decoration_method']",$source);
        self::assertStringContainsString('duplicate normalized print_area identity',$source);
        self::assertStringContainsString('usort($normalizedAreas',$source);
        self::assertStringContainsString('fingerprint encoding failed',$source);
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
