<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class U3RepairPackageIdentityStructureTest extends TestCase
{
    public function testRepairPackageUsesRunScopedZipAndSpecVariant(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/ProductFactory/Orchestrator.php');
        self::assertIsString($source);
        self::assertStringContainsString("\$repairGeneration=\$isRepair?substr(hash('sha256',DIGIFORGE_VERSION.'|'.\$key),0,10):'';", $source);
        self::assertStringContainsString("\$repairVariant=\$isRepair?'repair-'.\$planToken.'-'.\$repairGeneration:'';", $source);
        self::assertStringContainsString('$packageFilename=$this->approvedPackageFilename($spec,$productVersionId)', $source);
        self::assertStringContainsString("DIGIFORGE_VERSION.'|'.\$key", $source);
        self::assertStringContainsString('$this->producer->package($productVersionId,$packageInput,$packageFilename,$repairVariant)', $source);
        self::assertStringContainsString("'variant_key'=>\$variant", $source);
        self::assertStringContainsString('registerPackage($productVersionId,(int)$plan[\'id\'],$package,$key,$sequence,$repairVariant)', $source);
        self::assertStringContainsString("'external_publish'=>false", $source);
    }
}
