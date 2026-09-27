<?php

declare(strict_types=1);

use DigiForge\Orders\OrderReadinessProjection;
use PHPUnit\Framework\TestCase;

final class OrderReadinessProjectionTest extends TestCase
{
    public function testReadyDoesNotRequireExternalExecutionAuthorization(): void
    {
        $projection = OrderReadinessProjection::project(42, 'pod', [
            'order_approved' => true,
            'line_items_present' => true,
            'line_items_valid' => true,
            'provider_mappings_present' => true,
            'personalization_reviewed' => true,
            'human_approval' => true,
        ], false);

        self::assertTrue($projection['ready']);
        self::assertFalse($projection['external_fulfillment_authorized']);
        self::assertArrayNotHasKey('external_fulfillment_authorized', $projection['checks']);
    }

    public function testAnyFailedReadinessEvidenceKeepsOrderUnready(): void
    {
        $projection = OrderReadinessProjection::project(42, 'pod', [
            'order_approved' => true,
            'line_items_present' => true,
            'line_items_valid' => false,
        ], false);

        self::assertFalse($projection['ready']);
        self::assertFalse($projection['external_fulfillment_authorized']);
    }
}
