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
}
