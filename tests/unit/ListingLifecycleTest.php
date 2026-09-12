<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use DigiForge\Listings\Lifecycle;
use PHPUnit\Framework\TestCase;

final class ListingLifecycleTest extends TestCase
{
    public function testListingLifecycleIsFailClosed(): void
    {
        self::assertTrue(Lifecycle::can('listing', 'DRAFT', 'VALIDATED'));
        self::assertTrue(Lifecycle::can('listing', 'VALIDATED', 'REVIEW_REQUIRED'));
        self::assertTrue(Lifecycle::can('listing', 'REVIEW_REQUIRED', 'APPROVED'));
        self::assertFalse(Lifecycle::can('listing', 'DRAFT', 'APPROVED'));
        self::assertFalse(Lifecycle::can('listing', 'APPROVED', 'DRAFT'));
        self::assertFalse(Lifecycle::can('unknown', 'DRAFT', 'APPROVED'));
    }

    public function testEtsyIntentLifecycleNeverContainsExecutionState(): void
    {
        self::assertTrue(Lifecycle::can('intent', 'BLOCKED', 'READY_FOR_REVIEW'));
        self::assertTrue(Lifecycle::can('intent', 'READY_FOR_REVIEW', 'APPROVED_INTENT'));
        foreach (['EXECUTING', 'QUEUED', 'SUBMITTED', 'SYNCED', 'LIVE', 'PUBLISHED'] as $state) {
            self::assertFalse(Lifecycle::can('intent', 'BLOCKED', $state));
            self::assertFalse(Lifecycle::can('intent', 'APPROVED_INTENT', $state));
        }
    }
}
