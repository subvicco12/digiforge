<?php

declare(strict_types=1);

namespace DigiForge\Queue;

final class JobState
{
    public const ALL = [
        'QUEUED',
        'RUNNING',
        'WAITING',
        'RETRY',
        'SUCCESS',
        'FAILED',
        'BLOCKED',
        'CANCELLED',
        'HUMAN_REVIEW',
        'DEAD_LETTER',
    ];

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'BLOCKED' => ['QUEUED', 'CANCELLED', 'HUMAN_REVIEW'],
        'QUEUED' => ['RUNNING', 'BLOCKED', 'CANCELLED'],
        'RUNNING' => ['SUCCESS', 'RETRY', 'FAILED', 'BLOCKED', 'HUMAN_REVIEW', 'CANCELLED'],
        'WAITING' => ['QUEUED', 'BLOCKED', 'HUMAN_REVIEW', 'CANCELLED'],
        'RETRY' => ['QUEUED', 'DEAD_LETTER', 'CANCELLED'],
        'HUMAN_REVIEW' => ['QUEUED', 'BLOCKED', 'FAILED', 'CANCELLED'],
        'SUCCESS' => [],
        'FAILED' => [],
        'CANCELLED' => [],
        'DEAD_LETTER' => [],
    ];

    public static function valid(string $state): bool
    {
        return in_array($state, self::ALL, true);
    }

    public static function terminal(string $state): bool
    {
        return in_array($state, ['SUCCESS', 'FAILED', 'CANCELLED', 'DEAD_LETTER'], true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return isset(self::TRANSITIONS[$from]) && in_array($to, self::TRANSITIONS[$from], true);
    }
}
