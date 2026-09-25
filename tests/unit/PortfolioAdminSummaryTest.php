<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use DigiForge\ProductFactory\PortfolioAdminSummary;
use PHPUnit\Framework\TestCase;

final class PortfolioAdminSummaryTest extends TestCase
{
    public function testSummaryCombinesReadModelsWithoutEnablingExecution(): void
    {
        $result = PortfolioAdminSummary::compose(
            ['total'=>7,'ready_for_product_review'=>2,'ready_for_listing_review'=>1,'attention'=>[['x'=>1]]],
            ['failed'=>2,'attention'=>[['x'=>1],['x'=>2]]],
            ['ready_for_local_listing_preparation'=>3,'blocked'=>1,'attention'=>[['x'=>1]]]
        );
        self::assertSame(7, $result['products_total']);
        self::assertSame(2, $result['qa_failed']);
        self::assertSame(4, $result['attention_total']);
        self::assertTrue($result['gate3_approval_required']);
        self::assertTrue($result['external_execution_locked']);
        self::assertFalse($result['external_actions_performed']);
    }

    public function testMalformedCountersFailClosedToZero(): void
    {
        $result = PortfolioAdminSummary::compose(
            ['total'=>'7','ready_for_product_review'=>-1],
            ['failed'=>true],
            ['ready_for_local_listing_preparation'=>1.5,'blocked'=>[]]
        );
        self::assertSame(0, $result['products_total']);
        self::assertSame(0, $result['product_review_ready']);
        self::assertSame(0, $result['qa_failed']);
        self::assertSame(0, $result['listing_preparation_ready']);
        self::assertSame(0, $result['listing_blocked']);
    }
}
