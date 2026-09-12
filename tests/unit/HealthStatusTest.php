<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use DigiForge\Observability\HealthStatus;
use PHPUnit\Framework\TestCase;

final class HealthStatusTest extends TestCase
{
    public function testWorstCheckDeterminesAggregateHealth(): void
    {
        self::assertSame(HealthStatus::HEALTHY, HealthStatus::aggregate([]));
        self::assertSame(HealthStatus::DEGRADED, HealthStatus::aggregate([HealthStatus::HEALTHY, HealthStatus::DEGRADED]));
        self::assertSame(HealthStatus::UNHEALTHY, HealthStatus::aggregate([HealthStatus::DEGRADED, HealthStatus::UNHEALTHY]));
    }
}
