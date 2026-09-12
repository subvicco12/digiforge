<?php
declare(strict_types=1);
namespace DigiForge\AI;

final class Lifecycle {
    public const DRAFT='DRAFT';
    public const VALIDATED='VALIDATED';
    public const REVIEW_REQUIRED='REVIEW_REQUIRED';
    public const APPROVED_FOR_EXECUTION='APPROVED_FOR_EXECUTION';

    private const TRANSITIONS = [
        self::DRAFT => [self::VALIDATED],
        self::VALIDATED => [self::DRAFT, self::REVIEW_REQUIRED],
        self::REVIEW_REQUIRED => [self::VALIDATED, self::APPROVED_FOR_EXECUTION],
        self::APPROVED_FOR_EXECUTION => [self::REVIEW_REQUIRED],
    ];

    public static function canTransition(string $from,string $to): bool {
        return in_array($to,self::TRANSITIONS[$from]??[],true);
    }
    public static function isAllowed(string $state): bool { return isset(self::TRANSITIONS[$state]); }
    public static function executionState(string $state): bool {
        return in_array(strtoupper($state),['EXECUTING','EXECUTED','COMPLETED','SUCCEEDED','FAILED'],true);
    }
}
