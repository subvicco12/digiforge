<?php

declare(strict_types=1);

final class MigrationTest extends WP_UnitTestCase
{
    public function testSchemaSixPreservesOperationalQueueColumns(): void
    {
        DigiForge\Core\Activator::activate();

        global $wpdb;
        $columns = $wpdb->get_col('SHOW COLUMNS FROM ' . DigiForge\Database\Tables::jobs(), 0);

        self::assertContains('locked_by', $columns);
        self::assertContains('lease_expires_at', $columns);
        self::assertContains('next_attempt_at', $columns);
        self::assertContains('dead_lettered_at', $columns);
        self::assertSame(6, (int) get_option('digiforge_db_schema_version'));
    }

    public function testHealthSnapshotRemainsSafetyLocked(): void
    {
        DigiForge\Core\Activator::activate();

        $snapshot = (new DigiForge\Observability\HealthMonitor())->snapshot();

        self::assertTrue($snapshot['automation_locked']);
        self::assertSame(6, $snapshot['schema']['expected']);
        self::assertSame(6, $snapshot['schema']['current']);
    }
}
