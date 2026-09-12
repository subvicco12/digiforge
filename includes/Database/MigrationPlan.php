<?php

declare(strict_types=1);

namespace DigiForge\Database;

final class MigrationPlan
{
    public const LATEST = 8;

    /** @return list<int> */
    public static function pending(int $currentVersion): array
    {
        if ($currentVersion < 0 || $currentVersion > self::LATEST) {
            return [];
        }

        return $currentVersion === self::LATEST
            ? []
            : range($currentVersion + 1, self::LATEST);
    }
}
