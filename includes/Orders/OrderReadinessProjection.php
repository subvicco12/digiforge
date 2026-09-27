<?php

declare(strict_types=1);

namespace DigiForge\Orders;

final class OrderReadinessProjection
{
    /** @param array<string,bool> $checks */
    public static function project(int $orderId, string $fulfillmentMode, array $checks, bool $externalFulfillmentAuthorized = false): array
    {
        return [
            'order_id' => $orderId,
            'fulfillment_mode' => $fulfillmentMode,
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            // Execution authority is intentionally separate from readiness evidence.
            'external_fulfillment_authorized' => $externalFulfillmentAuthorized,
        ];
    }
}
