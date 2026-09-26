<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use DigiForge\Database\MigrationPlan;
use PHPUnit\Framework\TestCase;

final class MigrationPlanTest extends TestCase
{
    public function testPendingMigrationsAreOrderedAndResumable(): void
    {
        self::assertSame([1,2,3,4,5,6,7,8,9,10,11,12,13,14,15], MigrationPlan::pending(0));
        self::assertSame([3,4,5,6,7,8,9,10,11,12,13,14,15], MigrationPlan::pending(2));
        self::assertSame([5,6,7,8,9,10,11,12,13,14,15], MigrationPlan::pending(4));
        self::assertSame([6,7,8,9,10,11,12,13,14,15], MigrationPlan::pending(5));
        self::assertSame([7,8,9,10,11,12,13,14,15], MigrationPlan::pending(6));
        self::assertSame([8,9,10,11,12,13,14,15], MigrationPlan::pending(7));
        self::assertSame([9,10,11,12,13,14,15], MigrationPlan::pending(8));
        self::assertSame([10,11,12,13,14,15], MigrationPlan::pending(9));
        self::assertSame([11,12,13,14,15], MigrationPlan::pending(10));
        self::assertSame([12,13,14,15], MigrationPlan::pending(11));
        self::assertSame([13,14,15], MigrationPlan::pending(12));
        self::assertSame([14,15], MigrationPlan::pending(13));
        self::assertSame([15], MigrationPlan::pending(14));
        self::assertSame([], MigrationPlan::pending(15));
    }
}
