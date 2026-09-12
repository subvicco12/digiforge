<?php

declare(strict_types=1);

namespace DigiForge\Production;

final class Lifecycle
{
    /** @var array<string,list<string>> */
    private const SPEC = [
        'DRAFT' => ['SPECIFIED','REJECTED'],
        'SPECIFIED' => ['APPROVED','REJECTED'],
        'APPROVED' => [],
        'REJECTED' => [],
    ];

    /** @var array<string,list<string>> */
    private const PLAN = [
        'DRAFT' => ['VALIDATED','REJECTED'],
        'VALIDATED' => ['REVIEW_REQUIRED','REJECTED'],
        'REVIEW_REQUIRED' => ['APPROVED','REJECTED'],
        'APPROVED' => ['RELEASE_READY','REJECTED'],
        'RELEASE_READY' => [],
        'REJECTED' => [],
    ];

    /** @var array<string,list<string>> */
    private const INTENT = [
        'BLOCKED' => ['READY_FOR_REVIEW','REJECTED','SUPERSEDED'],
        'READY_FOR_REVIEW' => ['APPROVED_INTENT','REJECTED','SUPERSEDED'],
        'APPROVED_INTENT' => ['SUPERSEDED'],
        'REJECTED' => [],
        'SUPERSEDED' => [],
    ];

    /** @var array<string,list<string>> */
    private const REVISION = [
        'PENDING_QA' => ['QA_PASSED','QA_FAILED','REJECTED'],
        'QA_PASSED' => ['APPROVED','QA_FAILED','REJECTED'],
        'QA_FAILED' => ['REJECTED'],
        'APPROVED' => [],
        'REJECTED' => [],
    ];

    /** @var array<string,list<string>> */
    private const BUNDLE = [
        'DRAFT' => ['VALIDATED','REJECTED'],
        'VALIDATED' => ['REVIEW_REQUIRED','REJECTED'],
        'REVIEW_REQUIRED' => ['APPROVED','REJECTED'],
        'APPROVED' => ['RELEASE_READY','REJECTED'],
        'RELEASE_READY' => [],
        'REJECTED' => [],
    ];

    public static function canSpec(string $from, string $to): bool { return in_array($to, self::SPEC[$from] ?? [], true); }
    public static function canPlan(string $from, string $to): bool { return in_array($to, self::PLAN[$from] ?? [], true); }
    public static function canIntent(string $from, string $to): bool { return in_array($to, self::INTENT[$from] ?? [], true); }
    public static function canRevision(string $from, string $to): bool { return in_array($to, self::REVISION[$from] ?? [], true); }
    public static function canBundle(string $from, string $to): bool { return in_array($to, self::BUNDLE[$from] ?? [], true); }

    public static function can(string $entity, string $from, string $to): bool
    {
        return match ($entity) {
            'spec' => self::canSpec($from, $to),
            'plan' => self::canPlan($from, $to),
            'intent' => self::canIntent($from, $to),
            'revision' => self::canRevision($from, $to),
            'bundle' => self::canBundle($from, $to),
            default => false,
        };
    }
}
