<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use PHPUnit\Framework\TestCase;

final class U3ProductFactoryStructureTest extends TestCase
{
    public function testOrchestratorReusesApprovalGatedDevelopmentAndStopsAtProductReview(): void
    {
        $source=(string)file_get_contents(__DIR__.'/../../includes/ProductFactory/Orchestrator.php');
        self::assertStringContainsString('(new ExecutionEngine())->develop(', $source); self::assertStringContainsString('Workflow::PRODUCT_REVIEW_REQUIRED',$source); self::assertMatchesRegularExpression("/'external_actions_performed'\s*=>\s*false/",$source); self::assertStringNotContainsString('Printify',preg_replace('/\/\*.*?\*\//s','',$source)??$source); self::assertStringNotContainsString('Gelato',preg_replace('/\/\*.*?\*\//s','',$source)??$source); self::assertStringNotContainsString('EtsyOAuth',$source);
    }
    public function testProductReviewRequiresDeterministicAndSemanticQa(): void
    {
        $source=(string)file_get_contents(__DIR__.'/../../includes/ProductFactory/ProductReview.php'); self::assertStringContainsString("!== 'QA_PASSED'",$source); self::assertStringContainsString('planQaPassed',$source); self::assertStringContainsString("check_type LIKE 'semantic_%%'",$source); self::assertStringContainsString('total < 7',$source); self::assertStringContainsString('Workflow::PRODUCT_APPROVED',$source); self::assertStringContainsString('Etsy draft/publish remains blocked',$source); self::assertStringNotContainsString('ListingRepository',$source);
    }
    public function testSemanticQaIsIndependentAndFailClosed(): void
    {
        $source=(string)file_get_contents(__DIR__.'/../../includes/ProductFactory/SemanticQa.php'); self::assertStringContainsString('(new OpenAIClient())->develop(',$source); foreach(['specification_match','ip_trademark_risk','prohibited_content','link_qr_integrity','mockup_production_separation'] as $needle) self::assertStringContainsString("'{$needle}'",$source); self::assertStringContainsString("'passed' => false",$source);
    }
    public function testLaunchApiExposesBuildAndHumanReviewSeparately(): void
    {
        $source=(string)file_get_contents(__DIR__.'/../../includes/REST/LaunchController.php'); self::assertStringContainsString('/launch/candidates/(?P<id>\\d+)/build-product',$source); self::assertStringContainsString('/launch/product-versions/(?P<id>\\d+)/review',$source); self::assertStringContainsString("'etsy_publish_direct' => false",$source); self::assertStringContainsString("'printify_execution' => false",$source); self::assertStringContainsString("'gelato_execution' => false",$source);
    }
    public function testProductAndMarketingAssetsAreDistinct(): void
    {
        $source=(string)file_get_contents(__DIR__.'/../../includes/ProductFactory/Orchestrator.php'); $compact=preg_replace('/\s+/','',$source)??$source; self::assertStringContainsString("'asset_type'=>\$group==='marketing'?'marketing_asset':'product_asset'",$compact); self::assertMatchesRegularExpression("/\['group'\s*=>\s*'product'/",$source); self::assertMatchesRegularExpression("/\['group'\s*=>\s*'marketing'/",$source); self::assertStringContainsString("'product_package'",$source);
    }
    public function testApprovalAutomationChecksSchedulerResult(): void
    {
        $source=(string)file_get_contents(__DIR__.'/../../includes/ProductFactory/ApprovalAutomation.php'); $compact=preg_replace('/\\s+/','',$source)??$source; self::assertStringContainsString('$scheduled=false',$compact); self::assertStringContainsString('scheduler_rejected_job',$source); self::assertStringContainsString("'research_candidate_reviewed'",$source);
    }
    public function testGeneratedAssetsAreProtectedAndServedThroughAuthenticatedReview(): void
    {
        $storage=(string)file_get_contents(__DIR__.'/../../includes/ProductFactory/AssetStorage.php'); $review=(string)file_get_contents(__DIR__.'/../../includes/Portal/U3AssetReview.php'); $producer=(string)file_get_contents(__DIR__.'/../../includes/ProductFactory/LocalAssetProducer.php'); self::assertStringContainsString('Require all denied',$storage); self::assertStringContainsString('ensureProtectedRoot()',$producer); self::assertStringContainsString('current_user_can',$review); self::assertStringContainsString('check_admin_referer',$review); self::assertStringContainsString('AssetStorage::absolutePath',$review); self::assertStringContainsString('Content-Security-Policy',$review);
    }
    public function testLargeAiBudgetIsScopedToProductionManifestOnly(): void
    {
        $source=(string)file_get_contents(__DIR__.'/../../includes/Launch/OpenAIClient.php');
        self::assertStringContainsString("str_contains(\$brief, 'production-ready DigiForge asset manifest')",$source);
        self::assertStringContainsString('$productionManifest ? 16000 : 8000',$source);
        self::assertMatchesRegularExpression('/request\(\$brief\s*,\s*true\s*,\s*4000\)/',$source);
    }
}
