<?php

declare(strict_types=1);

namespace DigiForge\Finance;

final class Lifecycle
{
    private const PERIOD = [
        'OPEN' => ['CALCULATED', 'REVIEW_REQUIRED', 'REJECTED', 'SUPERSEDED'],
        'CALCULATED' => ['REVIEW_REQUIRED', 'REJECTED', 'SUPERSEDED'],
        'REVIEW_REQUIRED' => ['APPROVED', 'REJECTED', 'SUPERSEDED'],
        'APPROVED' => ['CLOSED', 'SUPERSEDED'],
        'REJECTED' => ['SUPERSEDED'],
        'SUPERSEDED' => [],
        'CLOSED' => [],
    ];

    private const ALERT = [
        'OPEN' => ['ACKNOWLEDGED', 'RESOLVED', 'DISMISSED', 'SUPERSEDED'],
        'ACKNOWLEDGED' => ['RESOLVED', 'DISMISSED', 'SUPERSEDED'],
        'RESOLVED' => ['SUPERSEDED'],
        'DISMISSED' => ['SUPERSEDED'],
        'SUPERSEDED' => [],
    ];

    public const INTENT_TYPES = [
        'PREPARE_RECONCILIATION',
        'PREPARE_TAX_REVIEW',
        'PREPARE_EXPORT',
        'PREPARE_ACCOUNTING_SYNC',
        'PREPARE_REFUND_REVIEW',
        'PREPARE_PAYOUT_REVIEW',
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
            'period' => self::PERIOD,
            'alert' => self::ALERT,
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
