<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use PHPUnit\Framework\TestCase;

final class U3ProductFactoryStructureTest extends TestCase
{
    public function testOrchestratorReusesApprovalGatedDevelopmentAndStopsAtProductReview(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../includes/ProductFactory/Orchestrator.php');
        self::assertStringContainsString('(new ExecutionEngine())->develop(', $source);
        self::assertStringContainsString('Workflow::PRODUCT_REVIEW_REQUIRED', $source);
        self::assertStringContainsString("'external_actions_performed' => false", $source);
        self::assertStringNotContainsString('Printify', preg_replace('/\/\*.*?\*\//s', '', $source) ?? $source);
        self::assertStringNotContainsString('Gelato', preg_replace('/\/\*.*?\*\//s', '', $source) ?? $source);
        self::assertStringNotContainsString('EtsyOAuth', $source);
    }

    public function testProductReviewRequiresDeterministicAndSemanticQa(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../includes/ProductFactory/ProductReview.php');
        self::assertStringContainsString("!== 'QA_PASSED'", $source);
        self::assertStringContainsString('planQaPassed', $source);
        self::assertStringContainsString("check_type LIKE 'semantic_%%'", $source);
        self::assertStringContainsString('total < 7', $source);
        self::assertStringContainsString('Workflow::PRODUCT_APPROVED', $source);
        self::assertStringContainsString('Etsy draft/publish remains blocked', $source);
        self::assertStringNotContainsString('ListingRepository', $source);
    }

    public function testSemanticQaIsIndependentAndFailClosed(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../includes/ProductFactory/SemanticQa.php');
        self::assertStringContainsString('(new OpenAIClient())->develop(', $source);
        self::assertStringContainsString("'specification_match'", $source);
        self::assertStringContainsString("'ip_trademark_risk'", $source);
        self::assertStringContainsString("'prohibited_content'", $source);
        self::assertStringContainsString("'link_qr_integrity'", $source);
        self::assertStringContainsString("'mockup_production_separation'", $source);
        self::assertStringContainsString("'passed' => false", $source);
    }

    public function testLaunchApiExposesBuildAndHumanReviewSeparately(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../includes/REST/LaunchController.php');
        self::assertStringContainsString('/launch/candidates/(?P<id>\\d+)/build-product', $source);
        self::assertStringContainsString('/launch/product-versions/(?P<id>\\d+)/review', $source);
        self::assertStringContainsString("'etsy_publish_direct' => false", $source);
        self::assertStringContainsString("'printify_execution' => false", $source);
        self::assertStringContainsString("'gelato_execution' => false", $source);
    }

    public function testProductAndMarketingAssetsAreDistinct(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../includes/ProductFactory/Orchestrator.php');
        self::assertStringContainsString("'marketing_asset' : 'product_asset'", $source);
        self::assertStringContainsString("['group' => 'product'", $source);
        self::assertStringContainsString("['group' => 'marketing'", $source);
        self::assertStringContainsString("'product_package'", $source);
    }

    public function testApprovalAutomationChecksSchedulerResult(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../includes/ProductFactory/ApprovalAutomation.php');
        self::assertStringContainsString('$scheduled = false', $source);
        self::assertStringContainsString('scheduler_rejected_job', $source);
        self::assertStringContainsString("'research_candidate_reviewed'", $source);
    }
}
