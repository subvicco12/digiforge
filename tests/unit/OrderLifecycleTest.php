<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use DigiForge\Orders\Lifecycle;
use PHPUnit\Framework\TestCase;

final class OrderLifecycleTest extends TestCase
{
    public function testOrderLifecycleIsFailClosed(): void
    {
        self::assertTrue(Lifecycle::can('order', 'RECEIVED', 'VALIDATED'));
        self::assertTrue(Lifecycle::can('order', 'REVIEW_REQUIRED', 'APPROVED'));
        self::assertFalse(Lifecycle::can('order', 'RECEIVED', 'APPROVED'));
        self::assertFalse(Lifecycle::can('order', 'APPROVED', 'RECEIVED'));
    }

    public function testFulfillmentIntentLifecycleNeverContainsExecutionState(): void
    {
        self::assertTrue(Lifecycle::can('intent', 'BLOCKED', 'READY_FOR_REVIEW'));
        self::assertTrue(Lifecycle::can('intent', 'READY_FOR_REVIEW', 'APPROVED_INTENT'));
        foreach (['QUEUED', 'EXECUTING', 'SUBMITTED', 'ACCEPTED', 'PROCESSING', 'FULFILLED', 'SHIPPED', 'REFUNDED', 'CANCELLED_REMOTE', 'SYNCED'] as $state) {
            self::assertFalse(Lifecycle::can('intent', 'BLOCKED', $state));
            self::assertFalse(Lifecycle::can('intent', 'APPROVED_INTENT', $state));
        }
    }
}
