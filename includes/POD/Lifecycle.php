<?php

declare(strict_types=1);

namespace DigiForge\POD;

final class Lifecycle
{
    /** @var array<string,array<string,list<string>>> */
    private const MAPS = [
        'catalog' => [
            'DRAFT' => ['OBSERVED','REJECTED'],
            'OBSERVED' => ['REVIEW_REQUIRED','REJECTED','SUPERSEDED'],
            'REVIEW_REQUIRED' => ['APPROVED','REJECTED','SUPERSEDED'],
            'APPROVED' => ['SUPERSEDED'],
            'REJECTED' => [],
            'SUPERSEDED' => [],
        ],
        'mapping' => [
            'DRAFT' => ['VALIDATED','REJECTED'],
            'VALIDATED' => ['REVIEW_REQUIRED','REJECTED','SUPERSEDED'],
            'REVIEW_REQUIRED' => ['APPROVED','REJECTED','SUPERSEDED'],
            'APPROVED' => ['SUPERSEDED'],
            'REJECTED' => [],
            'SUPERSEDED' => [],
        ],
        'print_area' => [
            'DRAFT' => ['VALIDATED','REJECTED'],
            'VALIDATED' => ['APPROVED','REJECTED'],
            'APPROVED' => [],
            'REJECTED' => [],
        ],
        'personalization' => [
            'DRAFT' => ['VALIDATED','REJECTED'],
            'VALIDATED' => ['REVIEW_REQUIRED','REJECTED','SUPERSEDED'],
            'REVIEW_REQUIRED' => ['APPROVED','REJECTED','SUPERSEDED'],
            'APPROVED' => ['SUPERSEDED'],
            'REJECTED' => [],
            'SUPERSEDED' => [],
        ],
        'intent' => [
            'BLOCKED' => ['READY_FOR_REVIEW','REJECTED','SUPERSEDED'],
            'READY_FOR_REVIEW' => ['APPROVED_INTENT','REJECTED','SUPERSEDED'],
            'APPROVED_INTENT' => ['SUPERSEDED'],
            'REJECTED' => [],
            'SUPERSEDED' => [],
        ],
        'cost' => [
            'DRAFT' => ['VALIDATED','SUPERSEDED'],
            'VALIDATED' => ['APPROVED','SUPERSEDED'],
            'APPROVED' => ['SUPERSEDED'],
            'SUPERSEDED' => [],
        ],
    ];

    public static function can(string $type, string $from, string $to): bool
    {
        return in_array($to, self::MAPS[$type][$from] ?? [], true);
    }
}
