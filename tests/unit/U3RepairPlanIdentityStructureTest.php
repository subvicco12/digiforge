<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class U3RepairPlanIdentityStructureTest extends TestCase
{
    public function testRepairRunUsesFreshPlanAndCapabilitySpecificationIdentity(): void
    {
        $source=file_get_contents(__DIR__.'/../../includes/ProductFactory/Orchestrator.php');
        self::assertIsString($source);
        self::assertStringContainsString("\$developmentKey=\$isRepair?\$key.'-development':'u3-auto-candidate-'", $source);
        self::assertStringContainsString('str_starts_with($key,\'u3-repair-\')', $source);
        self::assertStringContainsString('substr(hash(\'sha256\',$key),0,12)', $source);
        self::assertStringContainsString('$planKey=$isRepair?\'u3-repair-\'', $source);
        self::assertStringContainsString('$planVersion=$isRepair?\'U3 Repair \'', $source);
        self::assertStringContainsString("\$repairGeneration=\$isRepair?substr(hash('sha256',DIGIFORGE_VERSION.'|'.\$key),0,10):'';", $source);
        self::assertStringContainsString("\$planKey=\$isRepair?'u3-repair-'.\$planToken.'-'.\$repairGeneration:'u3-launch';", $source);
        self::assertStringContainsString("\$planVersion=\$isRepair?'U3 Repair '.\$planToken.' '.\$repairGeneration:'U3 Launch 1.0';", $source);
        self::assertStringContainsString("\$repairVariant=\$isRepair?'repair-'.\$planToken.'-'.\$repairGeneration:'';", $source);
    }

    public function testExternalActionsRemainDisabled(): void
    {
        $source=file_get_contents(__DIR__.'/../../includes/ProductFactory/Orchestrator.php');
        self::assertIsString($source);
        self::assertStringContainsString("'external_actions'=>false",$source);
        self::assertStringContainsString("'external_actions_performed'=>false",$source);
    }
}
