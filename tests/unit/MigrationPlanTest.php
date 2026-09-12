<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use DigiForge\Database\MigrationPlan;
use PHPUnit\Framework\TestCase;

final class MigrationPlanTest extends TestCase
{
    public function testPendingMigrationsAreOrderedAndResumable(): void
    {
        self::assertSame([1, 2, 3, 4, 5, 6, 7], MigrationPlan::pending(0));
        self::assertSame([3, 4, 5, 6, 7], MigrationPlan::pending(2));
        self::assertSame([5, 6, 7], MigrationPlan::pending(4));
        self::assertSame([6, 7], MigrationPlan::pending(5));
        self::assertSame([7], MigrationPlan::pending(6));
        self::assertSame([], MigrationPlan::pending(7));
    }
}
