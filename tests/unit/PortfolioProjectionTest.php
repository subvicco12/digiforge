<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use DigiForge\ProductFactory\PortfolioProjection;
use DigiForge\ProductFactory\Workflow;
use PHPUnit\Framework\TestCase;

final class PortfolioProjectionTest extends TestCase
{
    public function testPortfolioProjectionKeepsFailuresIsolatedAndExternalActionsFalse(): void
    {
        $summary = PortfolioProjection::summarize([
            [
                'product_id' => 10,
                'product_version_id' => 20,
                'research_approved' => true,
                'product_version_created' => true,
                'production_plan_created' => true,
                'assets_started' => true,
                'marketing_assets_started' => true,
                'qa_complete' => true,
                'qa_passed' => false,
            ],
            [
                'product_id' => 11,
                'product_version_id' => 21,
                'research_approved' => true,
                'product_version_created' => true,
                'production_plan_created' => true,
                'assets_started' => true,
                'marketing_assets_started' => true,
                'qa_complete' => true,
                'qa_passed' => true,
                'product_approved' => false,
            ],
        ]);

        self::assertSame(2, $summary['total']);
        self::assertSame(1, $summary['stages'][Workflow::QA_FAILED]);
        self::assertSame(1, $summary['stages'][Workflow::PRODUCT_REVIEW_REQUIRED]);
        self::assertSame(1, $summary['ready_for_product_review']);
        self::assertSame(0, $summary['ready_for_listing_review']);
        self::assertSame(10, $summary['attention'][0]['product_id']);
        self::assertFalse($summary['external_actions_performed']);
    }

    public function testEmptyPortfolioIsDeterministic(): void
    {
        self::assertSame([
            'total' => 0,
            'stages' => [],
            'ready_for_product_review' => 0,
            'ready_for_listing_review' => 0,
            'attention' => [],
            'external_actions_performed' => false,
        ], PortfolioProjection::summarize([]));
    }
}
