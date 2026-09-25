<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use DigiForge\Listings\BatchListingProjection;
use PHPUnit\Framework\TestCase;

final class BatchListingProjectionTest extends TestCase
{
    public function testReadyAndBlockedProductsRemainIsolated(): void
    {
        $result = BatchListingProjection::summarize([
            ['product_version_id'=>21,'product_approved'=>true,'release_bundle_ready'=>true,'listing_spec_complete'=>true],
            ['product_version_id'=>22,'product_approved'=>true,'release_bundle_ready'=>false,'listing_spec_complete'=>true],
        ]);

        self::assertSame(2, $result['total']);
        self::assertSame(1, $result['ready_for_local_listing_preparation']);
        self::assertSame(1, $result['blocked']);
        self::assertSame(['release_bundle_not_ready'], $result['attention'][0]['reasons']);
        self::assertTrue($result['gate3_approval_required']);
        self::assertSame(0, $result['draft_packages_created']);
        self::assertFalse($result['etsy_api_invoked']);
        self::assertFalse($result['external_actions_performed']);
    }

    public function testMalformedAndIncompleteItemsFailClosed(): void
    {
        $result = BatchListingProjection::summarize([
            'bad',
            ['product_version_id'=>0,'product_approved'=>false,'release_bundle_ready'=>false,'listing_spec_complete'=>false],
        ]);

        self::assertSame(2, $result['blocked']);
        self::assertSame(['invalid_item'], $result['attention'][0]['reasons']);
        self::assertSame(['invalid_product_version','gate2_not_approved','release_bundle_not_ready','listing_spec_incomplete'], $result['attention'][1]['reasons']);
    }

    public function testEmptyPortfolioDoesNotCreateAnything(): void
    {
        $result = BatchListingProjection::summarize([]);
        self::assertSame(0, $result['total']);
        self::assertSame(0, $result['draft_packages_created']);
        self::assertTrue($result['gate3_approval_required']);
    }
}
