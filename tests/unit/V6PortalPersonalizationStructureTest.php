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

    public function testPortalExposesAllFiveShopScopesAndPreservesSelection():void
    {
        $portal=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
        $model=file_get_contents(__DIR__.'/../../includes/Portal/ShopOperationsReadModel.php');
        foreach(['All Shops','Digital','Personalized POD','Standard POD','Jewelry'] as $label)self::assertStringContainsString($label,$model);
        self::assertStringContainsString("name=\"df_shop\"",$portal);
        self::assertStringContainsString("['df_view'=>\$view]",$portal);
    }

    public function testOrderRepositoryPersistsNormalizedTypedPersonalizationWithoutExecution():void
    {
        $repo=file_get_contents(__DIR__.'/../../includes/Orders/Repository.php');
        self::assertStringContainsString('createTypedPersonalization',$repo);
        self::assertStringContainsString('PersonalizationSubmissionNormalizer::normalize',$repo);
        self::assertStringContainsString("'external_execution_performed'=>false",$repo);
    }

    public function testAiRepositoryAggregatesRunDayMonthActualCost():void
    {
        $repo=file_get_contents(__DIR__.'/../../includes/AI/ShopAiGovernanceRepository.php');
        self::assertStringContainsString("run_id=%s",$repo);
        self::assertStringContainsString("occurred_at>=%s",$repo);
        self::assertStringContainsString('SUM(actual_cost)',$repo);
    }
}
