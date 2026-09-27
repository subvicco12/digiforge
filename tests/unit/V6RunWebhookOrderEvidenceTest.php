<?php
declare(strict_types=1);

use DigiForge\Orders\FulfillmentMode;
use DigiForge\Orders\OrderReadinessProjection;
use PHPUnit\Framework\TestCase;

final class V6RunWebhookOrderEvidenceTest extends TestCase
{
    public function testFulfillmentModesAndAuthorizationRemainIndependent():void
    {
        self::assertSame('digital',FulfillmentMode::classify(true,false));
        self::assertSame('pod',FulfillmentMode::classify(false,true));
        self::assertSame('hybrid',FulfillmentMode::classify(true,true));
        $ready=OrderReadinessProjection::project(7,'hybrid',['line_items_present'=>true,'provider_mappings_present'=>true],false);
        self::assertTrue($ready['ready']);
        self::assertFalse($ready['external_fulfillment_authorized']);
        $blocked=OrderReadinessProjection::project(8,'pod',['line_items_present'=>true,'provider_mappings_present'=>false],false);
        self::assertFalse($blocked['ready']);
    }

    public function testWebhookReconciliationNeverRetries():void
    {
        $model=file_get_contents(__DIR__.'/../../includes/Listings/WebhookReconciliationReadModel.php');
        self::assertStringContainsString('RESULT_HASH_MISMATCH',$model);
        self::assertStringContainsString('PROCESSING_STATUS_MISMATCH',$model);
        self::assertStringContainsString("'retry_recommended'=>false",$model);
        self::assertStringContainsString("'retry_performed'=>false",$model);
        self::assertStringNotContainsString('wp_remote_',$model);
    }

    public function testExplicitRunContextRejectsInvalidBoundaries():void
    {
        require_once __DIR__.'/../../includes/AI/AiRunContext.php';
        $invalid=\DigiForge\AI\AiRunContext::normalize('','2026-09-27 10:00:00');
        self::assertInstanceOf(WP_Error::class,$invalid);
        $model=file_get_contents(__DIR__.'/../../includes/AI/ShopAiGovernanceRepository.php');
        self::assertStringNotContainsString("policy['run_started_at']",$model);
        self::assertStringContainsString("'explicit'=>".'$explicit',$model);
        self::assertStringContainsString('EXPLICIT_RUN_CONTEXT_REQUIRED',$model);
        self::assertStringContainsString('AiRunContext::normalize',$model);
        self::assertStringContainsString('$runContext=null',$model);
    }
    public function testFulfillmentPlanBindsReviewedPersonalizationEvidence():void
    {
        $repo=file_get_contents(__DIR__.'/../../includes/Orders/Repository.php');
        self::assertStringContainsString('personalizationEvidenceHash',$repo);
        self::assertStringContainsString("'human_review_required'=>true",$repo);
        self::assertStringContainsString("'external_execution_performed'=>false",$repo);
        $portal=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
        self::assertStringContainsString('Evidence discrepancies',$portal);
        self::assertStringContainsString('never retries webhook processing automatically',$portal);
    }

}
