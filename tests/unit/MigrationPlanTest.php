<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use DigiForge\Database\MigrationPlan;
use PHPUnit\Framework\TestCase;

final class MigrationPlanTest extends TestCase
{
    public function testPendingMigrationsAreOrderedAndResumable(): void
    {
        self::assertSame(range(1, MigrationPlan::LATEST), MigrationPlan::pending(0));
        self::assertSame(range(3, MigrationPlan::LATEST), MigrationPlan::pending(2));
        self::assertSame(range(5, MigrationPlan::LATEST), MigrationPlan::pending(4));
        self::assertSame(range(6, MigrationPlan::LATEST), MigrationPlan::pending(5));
        self::assertSame(range(7, MigrationPlan::LATEST), MigrationPlan::pending(6));
        self::assertSame(range(8, MigrationPlan::LATEST), MigrationPlan::pending(7));
        self::assertSame(range(9, MigrationPlan::LATEST), MigrationPlan::pending(8));
        self::assertSame(range(10, MigrationPlan::LATEST), MigrationPlan::pending(9));
        self::assertSame(range(11, MigrationPlan::LATEST), MigrationPlan::pending(10));
        self::assertSame(range(12, MigrationPlan::LATEST), MigrationPlan::pending(11));
        self::assertSame(range(13, MigrationPlan::LATEST), MigrationPlan::pending(12));
        self::assertSame(range(14, MigrationPlan::LATEST), MigrationPlan::pending(13));
        self::assertSame(range(15, MigrationPlan::LATEST), MigrationPlan::pending(14));
        self::assertSame(range(16, MigrationPlan::LATEST), MigrationPlan::pending(15));
        self::assertSame(range(17, MigrationPlan::LATEST), MigrationPlan::pending(16));
        self::assertSame(range(18, MigrationPlan::LATEST), MigrationPlan::pending(17));
        self::assertSame(range(19, MigrationPlan::LATEST), MigrationPlan::pending(18));
        self::assertSame(range(20, MigrationPlan::LATEST), MigrationPlan::pending(19));
        self::assertSame(range(21, MigrationPlan::LATEST), MigrationPlan::pending(20));
        self::assertSame(range(22, MigrationPlan::LATEST), MigrationPlan::pending(21));
        self::assertSame(range(23, MigrationPlan::LATEST), MigrationPlan::pending(22));
        self::assertSame([24], MigrationPlan::pending(23));
        self::assertSame([], MigrationPlan::pending(MigrationPlan::LATEST));
    }
}
