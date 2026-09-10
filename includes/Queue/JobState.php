<?php
declare(strict_types=1);
namespace DigiForge\Queue;
final class JobState {
    public const ALL = ['QUEUED','RUNNING','WAITING','RETRY','SUCCESS','FAILED','BLOCKED','CANCELLED','HUMAN_REVIEW'];
    public static function valid(string $state): bool { return in_array($state, self::ALL, true); }
    public static function terminal(string $state): bool { return in_array($state, ['SUCCESS','FAILED','CANCELLED'], true); }
}
