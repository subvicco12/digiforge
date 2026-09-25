<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use DigiForge\Listings\AdminStateSummary;
use PHPUnit\Framework\TestCase;

final class ListingAdminStateSummaryTest extends TestCase
{
    public function testSummarizesPersistedStatesWithoutExecution(): void
    {
        $result = AdminStateSummary::summarize([
            ['state'=>'draft'], ['state'=>'APPROVED'], ['state'=>'DRAFT'],
        ]);
        self::assertSame(3, $result['total']);
        self::assertSame(['APPROVED'=>1,'DRAFT'=>2], $result['states']);
        self::assertSame(0, $result['invalid']);
        self::assertTrue($result['gate3_approval_required']);
        self::assertFalse($result['etsy_api_invoked']);
        self::assertFalse($result['external_actions_performed']);
    }

    public function testMalformedRowsAreVisibleInsteadOfSilentlyPassing(): void
    {
        $result = AdminStateSummary::summarize([[], 'bad', ['state'=>'']]);
        self::assertSame(3, $result['total']);
        self::assertSame([], $result['states']);
        self::assertSame(3, $result['invalid']);
    }
}
