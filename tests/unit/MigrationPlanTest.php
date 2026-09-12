<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use DigiForge\Database\MigrationPlan;
use PHPUnit\Framework\TestCase;

final class MigrationPlanTest extends TestCase
{
    public function testPendingMigrationsAreOrderedAndResumable(): void
    {
        self::assertSame([1, 2, 3, 4, 5], MigrationPlan::pending(0));
        self::assertSame([3, 4, 5], MigrationPlan::pending(2));
        self::assertSame([5], MigrationPlan::pending(4));
        self::assertSame([], MigrationPlan::pending(5));
        self::assertSame([], MigrationPlan::pending(6));
    }
}
