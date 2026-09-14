<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use DigiForge\ProductFactory\Workflow;
use PHPUnit\Framework\TestCase;

final class U3WorkflowTest extends TestCase
{
    public function testPendingResearchCannotAppearAsProductionReady(): void
    {
        self::assertSame(Workflow::RESEARCH_PENDING, Workflow::project([]));
    }

    public function testPassingQaStopsAtHumanProductReview(): void
    {
        self::assertSame(Workflow::PRODUCT_REVIEW_REQUIRED, Workflow::project([
            'research_approved' => true,
            'product_version_created' => true,
            'production_plan_created' => true,
            'assets_started' => true,
            'marketing_assets_started' => true,
            'qa_complete' => true,
            'qa_passed' => true,
            'product_approved' => false,
        ]));
    }

    public function testQaFailureCannotReachReviewGate(): void
    {
        self::assertSame(Workflow::QA_FAILED, Workflow::project([
            'research_approved' => true,
            'product_version_created' => true,
            'production_plan_created' => true,
            'assets_started' => true,
            'marketing_assets_started' => true,
            'qa_complete' => true,
            'qa_passed' => false,
        ]));
    }

    public function testProductApprovalStillDoesNotMeanPublished(): void
    {
        self::assertSame(Workflow::PRODUCT_APPROVED, Workflow::project([
            'research_approved' => true,
            'product_version_created' => true,
            'production_plan_created' => true,
            'assets_started' => true,
            'marketing_assets_started' => true,
            'qa_complete' => true,
            'qa_passed' => true,
            'product_approved' => true,
            'listing_started' => false,
        ]));
    }
}
