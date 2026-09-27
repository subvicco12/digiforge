<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class V6PortalPersonalizationStructureTest extends TestCase
{
    public function testPortalUsesGovernedReadModelAndNoProductionAuthorityEscalation():void
    {
        $portal=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
        $model=file_get_contents(__DIR__.'/../../includes/Portal/ShopOperationsReadModel.php');
        self::assertStringContainsString("snapshot('personalized_pod')",$portal);
        self::assertStringContainsString('production_authority',$portal);
        self::assertStringContainsString("'external_execution_performed'=>false",$model);
    }

    public function testAiRepositoryAggregatesRunDayMonthActualCost():void
    {
        $repo=file_get_contents(__DIR__.'/../../includes/AI/ShopAiGovernanceRepository.php');
        self::assertStringContainsString("['run'=>\$runStart,'day'=>\$day,'month'=>\$month]",$repo);
        self::assertStringContainsString('SUM(actual_cost)',$repo);
    }
}
