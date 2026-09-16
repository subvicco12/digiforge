<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class U3RepairAssetSpecIdentityStructureTest extends TestCase
{
    public function testRepairRunScopesEveryGeneratedAssetSpecToRepairVariant(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/ProductFactory/Orchestrator.php');
        self::assertIsString($source);
        self::assertStringContainsString("\$isRepair?'repair-'.\$planToken:''", $source);
        self::assertStringContainsString("string \$variant=''", $source);
        self::assertStringContainsString("'variant_key'=>\$variant", $source);
    }

    public function testInitialRunStillUsesEmptyVariantAndRepairPackageUsesSameRunVariant(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/ProductFactory/Orchestrator.php');
        self::assertIsString($source);
        self::assertStringContainsString("\$repairVariant=\$isRepair?'repair-'.\$planToken:''", $source);
        self::assertStringContainsString('$this->producer->package($productVersionId,$packageInput,$packageFilename,$repairVariant)', $source);
        self::assertStringContainsString('registerPackage($productVersionId,(int)$plan[\'id\'],$package,$key,$sequence,$repairVariant)', $source);
    }
}
