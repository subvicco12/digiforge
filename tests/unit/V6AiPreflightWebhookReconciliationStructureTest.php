<?php
declare(strict_types=1);

use DigiForge\Listings\WebhookReconciliationReadModel;
use PHPUnit\Framework\TestCase;

final class V6AiPreflightWebhookReconciliationStructureTest extends TestCase
{
    public function testWebhookReconciliationNeverRetriesOrExecutesExternally():void
    {
        $model=file_get_contents(__DIR__.'/../../includes/Listings/WebhookReconciliationReadModel.php');
        self::assertStringContainsString("'retry_performed'=>false",$model);
        self::assertStringContainsString("'external_execution_performed'=>false",$model);
        self::assertStringContainsString("'PROCESSED'",$model);
        self::assertStringContainsString("'FAILED'",$model);
        self::assertStringContainsString("'VERIFIED'",$model);
    }

    public function testAiRepositoryExposesPreflightBeforeUsageRecording():void
    {
        $repo=file_get_contents(__DIR__.'/../../includes/AI/ShopAiGovernanceRepository.php');
        self::assertLessThan(strpos($repo,'public function recordUsage'),strpos($repo,'public function preflight'));
        self::assertStringContainsString('ShopAiPlan::preflight',$repo);
    }
    public function testBusinessKpisDoNotInventMissingDenominators():void
    {
        $model=file_get_contents(__DIR__.'/../../includes/AI/CostKpiReadModel.php');
        foreach(['cost_per_opportunity','cost_per_qualified_opportunity','cost_per_developed_product','cost_per_approved_listing','cost_per_sale','cost_per_gross_profit'] as $kpi)self::assertStringContainsString("'".$kpi."'=>null",$model);
        self::assertStringContainsString('UNAVAILABLE_WITHOUT_ATTRIBUTABLE_DENOMINATORS',$model);
    }

    public function testListingsPortalSurfacesReadOnlyWebhookReconciliation():void
    {
        $portal=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
        self::assertStringContainsString('Etsy webhook reconciliation',$portal);
        self::assertStringContainsString('This view never retries Etsy actions.',$portal);
    }

}
