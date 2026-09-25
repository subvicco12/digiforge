<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use PHPUnit\Framework\TestCase;

final class ListingAdminVisibilityTest extends TestCase
{
    public function testListingAdminSurfaceIsBoundedAndReadOnly(): void
    {
        $source=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/Admin.php');
        self::assertStringContainsString('private const SAMPLE_LIMIT = 100;', $source);
        self::assertStringContainsString('AdminStateSummary::summarize', $source);
        self::assertStringContainsString('SELECT state FROM ', $source);
        self::assertStringContainsString('Snapshot is read-only', $source);
        self::assertStringContainsString('Etsy API invoked', $source);
        self::assertStringContainsString('External actions performed', $source);
        self::assertStringNotContainsString('createDraftPackage(', $source);
        self::assertStringNotContainsString('createIntent(', $source);
        self::assertStringNotContainsString('->transition(', $source);
    }
}
