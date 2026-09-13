<?php

declare(strict_types=1);

namespace DigiForge\Operations;

final class RetentionPolicy
{
    private const IMMUTABLE = [
        'finance_ledger',
        'fx_snapshots',
        'analytics_snapshots',
        'audit_log',
        'release_bundle_revisions',
    ];

    private const DEFAULT_DAYS = [
        'health_events' => 365,
        'operational_alerts' => 730,
        'research_observations' => 730,
        'ai_usage' => 730,
    ];

    public static function isImmutable(string $recordType): bool
    {
        return in_array($recordType, self::IMMUTABLE, true);
    }

    public static function retentionDays(string $recordType): ?int
    {
        if (self::isImmutable($recordType)) {
            return null;
        }

        return self::DEFAULT_DAYS[$recordType] ?? null;
    }

    public static function automaticDeletionAllowed(string $recordType): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public static function describe(): array
    {
        return [
            'immutable_record_types' => self::IMMUTABLE,
            'default_retention_days' => self::DEFAULT_DAYS,
            'automatic_deletion_enabled' => false,
            'human_review_required' => true,
        ];
    }
}
