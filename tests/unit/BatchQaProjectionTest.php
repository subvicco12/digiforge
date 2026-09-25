<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use DigiForge\ProductFactory\BatchQaProjection;
use PHPUnit\Framework\TestCase;

final class BatchQaProjectionTest extends TestCase
{
    public function testFailuresAreIsolatedWithoutBlockingSuccessfulItems(): void
    {
        $result = BatchQaProjection::summarize([
            ['product_version_id'=>10,'asset_id'=>101,'passed'=>true,'checks'=>[['name'=>'checksum_match','passed'=>true]]],
            ['product_version_id'=>11,'asset_id'=>102,'passed'=>false,'checks'=>[['name'=>'checksum_match','passed'=>false],['name'=>'format_integrity','passed'=>true]]],
        ]);

        self::assertSame(2, $result['total']);
        self::assertSame(1, $result['passed']);
        self::assertSame(1, $result['failed']);
        self::assertFalse($result['all_passed']);
        self::assertSame([['index'=>1,'product_version_id'=>11,'asset_id'=>102,'failed_checks'=>['checksum_match']]], $result['attention']);
        self::assertFalse($result['external_actions_performed']);
    }

    public function testEmptyBatchDoesNotReportAllPassed(): void
    {
        $result = BatchQaProjection::summarize([]);
        self::assertSame(0, $result['total']);
        self::assertFalse($result['all_passed']);
        self::assertSame([], $result['attention']);
    }

    public function testMalformedItemFailsClosedAlongsidePassingItem(): void
    {
        $result = BatchQaProjection::summarize([
            ['product_version_id'=>13,'asset_id'=>103,'passed'=>true,'checks'=>[['name'=>'checksum_match','passed'=>true]]],
            'malformed',
        ]);
        self::assertSame(2, $result['total']);
        self::assertSame(1, $result['passed']);
        self::assertSame(1, $result['failed']);
        self::assertFalse($result['all_passed']);
        self::assertSame(['invalid_item'], $result['attention'][0]['failed_checks']);
    }

    public function testMissingChecksFailsClosed(): void
    {
        $result = BatchQaProjection::summarize([['product_version_id'=>12,'passed'=>true]]);
        self::assertSame(1, $result['failed']);
        self::assertFalse($result['all_passed']);
    }
}
