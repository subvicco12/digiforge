<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class F1RepairReplayHardeningStructureTest extends TestCase
{
    public function testRepairIdentityIsNormalizedAcrossAssetsPackagesAndBundles(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/ProductFactory/Orchestrator.php');
        self::assertIsString($source);
        self::assertStringContainsString("$repairVariant=$isRepair?'repair-'.$planToken.'-'.substr(hash('sha256',DIGIFORGE_VERSION.'|'.$key),0,10):'';", $source);
        self::assertStringContainsString('$definition,$key,$sequence++,$repairVariant', $source);
        self::assertStringContainsString('$packageFilename=$this->approvedPackageFilename($spec,$productVersionId)', $source);
        self::assertStringContainsString('$bundleKey=$isRepair?\'u3-product-review-\'.$planToken:\'u3-product-review\';', $source);
        self::assertStringContainsString('$bundleVersion=$isRepair?\'Repair \'.$planToken:\'Launch 1.0\';', $source);
    }

    public function testPersistenceBoundariesNormalizeVariantAndKeepExternalActionsOff(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/ProductFactory/Orchestrator.php');
        self::assertIsString($source);
        self::assertStringContainsString('$variant=sanitize_key($variant);$assetKey=', $source);
        self::assertStringContainsString('$variant=sanitize_key($variant);$spec=$this->production->createSpec', $source);
        self::assertStringContainsString("'external_publish'=>false", $source);
        self::assertStringContainsString("'external_actions_performed'=>false", $source);
    }
}
