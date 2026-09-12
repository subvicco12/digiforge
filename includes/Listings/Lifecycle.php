<?php

declare(strict_types=1);

namespace DigiForge\Listings;

final class Lifecycle
{
    /** @var array<string,list<string>> */
    private const LISTING = [
        'DRAFT' => ['VALIDATED','REJECTED'],
        'VALIDATED' => ['REVIEW_REQUIRED','REJECTED'],
        'REVIEW_REQUIRED' => ['APPROVED','REJECTED'],
        'APPROVED' => ['SUPERSEDED'],
        'REJECTED' => ['SUPERSEDED'],
        'SUPERSEDED' => [],
    ];

    /** @var array<string,list<string>> */
    private const INTENT = [
        'BLOCKED' => ['READY_FOR_REVIEW','REJECTED','SUPERSEDED'],
        'READY_FOR_REVIEW' => ['APPROVED_INTENT','REJECTED','SUPERSEDED'],
        'APPROVED_INTENT' => ['SUPERSEDED'],
        'REJECTED' => ['SUPERSEDED'],
        'SUPERSEDED' => [],
    ];

    public static function can(string $type, string $from, string $to): bool
    {
        $map = match ($type) {
            'listing' => self::LISTING,
            'intent' => self::INTENT,
            default => [],
        };
        return isset($map[$from]) && in_array($to, $map[$from], true);
    }
}
