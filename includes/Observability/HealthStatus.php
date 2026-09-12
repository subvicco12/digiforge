<?php

declare(strict_types=1);

namespace DigiForge\Observability;

final class HealthStatus
{
    public const HEALTHY = 'HEALTHY';
    public const DEGRADED = 'DEGRADED';
    public const UNHEALTHY = 'UNHEALTHY';

    public static function valid(string $status): bool
    {
        return in_array($status, [self::HEALTHY, self::DEGRADED, self::UNHEALTHY], true);
    }

    /** @param list<string> $checks */
    public static function aggregate(array $checks): string
    {
        if (in_array(self::UNHEALTHY, $checks, true)) {
            return self::UNHEALTHY;
        }

        return in_array(self::DEGRADED, $checks, true) ? self::DEGRADED : self::HEALTHY;
    }
}
