<?php
declare(strict_types=1);

namespace DigiForge\Listings;

/**
 * Canonical lifecycle for future Etsy external-operation records.
 * No method in this class performs an external action.
 */
final class EtsyOperationLifecycle
{
    public const NOT_SENT = 'NOT_SENT';
    public const SENT = 'SENT';
    public const CONFIRMED_SUCCESS = 'CONFIRMED_SUCCESS';
    public const CONFIRMED_FAILURE = 'CONFIRMED_FAILURE';
    public const UNKNOWN = 'UNKNOWN';
    public const RECONCILIATION = 'RECONCILIATION';
    public const RECONCILED = 'RECONCILED';

    /** @return list<string> */
    public static function states(): array
    {
        return [
            self::NOT_SENT, self::SENT, self::CONFIRMED_SUCCESS,
            self::CONFIRMED_FAILURE, self::UNKNOWN,
            self::RECONCILIATION, self::RECONCILED,
        ];
    }

    public static function canTransition(string $from, string $to): bool
    {
        $graph = [
            self::NOT_SENT => [self::SENT],
            self::SENT => [self::CONFIRMED_SUCCESS, self::CONFIRMED_FAILURE, self::UNKNOWN],
            self::UNKNOWN => [self::RECONCILIATION],
            self::RECONCILIATION => [self::RECONCILED, self::UNKNOWN],
            self::RECONCILED => [self::CONFIRMED_SUCCESS, self::CONFIRMED_FAILURE],
            self::CONFIRMED_SUCCESS => [],
            self::CONFIRMED_FAILURE => [],
        ];
        return in_array($to, $graph[$from] ?? [], true);
    }

    public static function retryPermitted(string $state): bool
    {
        return $state === self::CONFIRMED_FAILURE;
    }

    public static function reconciliationRequired(string $state): bool
    {
        return in_array($state, [self::UNKNOWN, self::RECONCILIATION], true);
    }
}
