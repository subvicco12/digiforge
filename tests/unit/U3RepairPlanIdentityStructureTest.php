<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class U3RepairPlanIdentityStructureTest extends TestCase
{
    public function testRepairRunUsesFreshPlanIdentityButStableProductDevelopmentIdentity(): void
    {
        $source=file_get_contents(__DIR__.'/../../includes/ProductFactory/Orchestrator.php');
        self::assertIsString($source);
        self::assertStringContainsString("$developmentKey='u3-auto-candidate-'",$source);
        self::assertStringContainsString("str_starts_with($key,'u3-repair-')",$source);
        self::assertStringContainsString("substr(hash('sha256',$key),0,12)",$source);
        self::assertStringContainsString("$planKey=$isRepair?'u3-repair-'",$source);
        self::assertStringContainsString("$planVersion=$isRepair?'U3 Repair '",$source);
    }

    public function testExternalActionsRemainDisabled(): void
    {
        $source=file_get_contents(__DIR__.'/../../includes/ProductFactory/Orchestrator.php');
        self::assertStringContainsString("'external_actions'=>false",$source);
        self::assertStringContainsString("'external_actions_performed'=>false",$source);
    }
}
