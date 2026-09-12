<?php

declare(strict_types=1);

namespace DigiForge\Orders;

final class Lifecycle
{
    private const ORDER = [
        'RECEIVED' => ['VALIDATED', 'REVIEW_REQUIRED', 'ON_HOLD', 'REJECTED', 'SUPERSEDED'],
        'VALIDATED' => ['REVIEW_REQUIRED', 'ON_HOLD', 'REJECTED', 'SUPERSEDED'],
        'REVIEW_REQUIRED' => ['APPROVED', 'ON_HOLD', 'REJECTED', 'SUPERSEDED'],
        'APPROVED' => ['ON_HOLD', 'CLOSED', 'SUPERSEDED'],
        'ON_HOLD' => ['REVIEW_REQUIRED', 'REJECTED', 'SUPERSEDED'],
        'REJECTED' => ['SUPERSEDED'],
        'SUPERSEDED' => [],
        'CLOSED' => [],
    ];

    private const PLAN = [
        'DRAFT' => ['VALIDATED', 'REVIEW_REQUIRED', 'REJECTED', 'ON_HOLD', 'SUPERSEDED'],
        'VALIDATED' => ['REVIEW_REQUIRED', 'REJECTED', 'ON_HOLD', 'SUPERSEDED'],
        'REVIEW_REQUIRED' => ['APPROVED', 'REJECTED', 'ON_HOLD', 'SUPERSEDED'],
        'APPROVED' => ['ON_HOLD', 'SUPERSEDED'],
        'REJECTED' => ['SUPERSEDED'],
        'ON_HOLD' => ['REVIEW_REQUIRED', 'REJECTED', 'SUPERSEDED'],
        'SUPERSEDED' => [],
    ];

    public const INTENT_TYPES = [
        'PREPARE_ORDER',
        'PREPARE_FULFILLMENT',
        'PREPARE_PERSONALIZATION',
        'PREPARE_SHIPPING',
        'PREPARE_CANCELLATION',
        'PREPARE_REFUND_REVIEW',
    ];

    public const INTENT_STATES = [
        'BLOCKED',
        'READY_FOR_REVIEW',
        'APPROVED_INTENT',
        'REJECTED',
        'SUPERSEDED',
    ];

    public static function can(string $type, string $from, string $to): bool
    {
        $map = match ($type) {
            'order' => self::ORDER,
            'plan' => self::PLAN,
            'intent' => [
                'BLOCKED' => ['READY_FOR_REVIEW', 'REJECTED', 'SUPERSEDED'],
                'READY_FOR_REVIEW' => ['APPROVED_INTENT', 'REJECTED', 'SUPERSEDED'],
                'APPROVED_INTENT' => ['SUPERSEDED'],
                'REJECTED' => ['SUPERSEDED'],
                'SUPERSEDED' => [],
            ],
            default => [],
        };

        return in_array($to, $map[$from] ?? [], true);
    }
}
