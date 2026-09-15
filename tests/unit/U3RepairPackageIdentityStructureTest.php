<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class U3RepairPackageIdentityStructureTest extends TestCase
{
    public function testRepairPackageUsesRunScopedZipAndSpecVariant(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/ProductFactory/Orchestrator.php');
        self::assertIsString($source);
        self::assertStringContainsString("$packageFilename=$isRepair?'digicraftify-product-'", $source);
        self::assertStringContainsString("'-repair-'.$planToken.'.zip'", $source);
        self::assertStringContainsString("$packageVariant=$isRepair?'repair-'.$planToken:''", $source);
        self::assertStringContainsString("'variant_key'=>$variant", $source);
        self::assertStringContainsString("registerPackage($productVersionId,(int)$plan['id'],$package,$key,$sequence,$packageVariant)", $source);
        self::assertStringContainsString("'external_publish'=>false", $source);
    }
}
