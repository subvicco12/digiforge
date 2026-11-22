<?php

declare(strict_types=1);

final class PodSchemaReconciliationTest extends WP_UnitTestCase
{
    public function testRepeatedReconciliationDoesNotIssueAlterStatements(): void
    {
        self::assertTrue(DigiForge\Database\PodSchema::migrateIfNeeded());
        $alterStatements = [];
        $observe = static function (string $query) use (&$alterStatements): string {
            if (preg_match('/^ALTER TABLE /i', $query)) {
                $alterStatements[] = $query;
            }
            return $query;
        };
        add_filter('query', $observe);
        try {
            self::assertTrue(DigiForge\Database\PodSchema::migrateIfNeeded());
        } finally {
            remove_filter('query', $observe);
        }
        self::assertSame([], $alterStatements);
    }
    public function testReconciliationPreservesStoredDraftGeometryAndGlobalVersion(): void
    {
        global $wpdb;
        $table = DigiForge\Database\Tables::pod_print_areas();
        self::assertNotFalse($wpdb->insert($table, [
            'provider_mapping_id' => 987654,
            'asset_spec_id' => 987654,
            'area_key' => 'synthetic-schema-regression',
            'dpi_target' => 150,
            'state' => 'DRAFT',
            'created_at' => '2026-10-17 00:00:00',
            'updated_at' => '2026-10-17 00:00:00',
        ]));
        $id = (int) $wpdb->insert_id;
        $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);
        $version = get_option('digiforge_db_schema_version');
        self::assertTrue(DigiForge\Database\PodSchema::migrateIfNeeded());
        self::assertTrue(DigiForge\Database\PodSchema::migrateIfNeeded());
        self::assertSame($before, $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A));
        self::assertSame($version, get_option('digiforge_db_schema_version'));
    }

    public function testReconciliationStillRepairsActualColumnTypeDrift(): void
    {
        global $wpdb;
        $table = DigiForge\Database\Tables::pod_print_areas();
        self::assertNotFalse($wpdb->query("ALTER TABLE {$table} MODIFY dpi_target int(10) unsigned NOT NULL DEFAULT 0"));
        try {
            self::assertTrue(DigiForge\Database\PodSchema::migrateIfNeeded());
            $column = $wpdb->get_row("SHOW COLUMNS FROM {$table} LIKE 'dpi_target'", ARRAY_A);
            self::assertIsArray($column);
            self::assertSame('smallint(5) unsigned', $column['Type']);
        } finally {
            // Restore the shared disposable fixture even if the assertion fails.
            $wpdb->query("ALTER TABLE {$table} MODIFY dpi_target smallint(5) unsigned NOT NULL DEFAULT 0");
        }
    }
    public function testFailedDriftRepairFailsClosedAndCanRecover(): void
    {
        global $wpdb;
        $table = DigiForge\Database\Tables::pod_print_areas();
        self::assertNotFalse($wpdb->query("ALTER TABLE {$table} MODIFY dpi_target int(10) unsigned NOT NULL DEFAULT 0"));
        $deny = static function (string $query) use ($table): string {
            if (str_starts_with($query, "ALTER TABLE {$table} CHANGE COLUMN `dpi_target`")) {
                return 'SELECT FROM /* synthetic schema failure: no write can execute */';
            }
            return $query;
        };
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $deny);
        try {
            self::assertFalse(DigiForge\Database\PodSchema::migrateIfNeeded());
            self::assertSame('POD_SCHEMA_UPDATE_FAILED', get_option('digiforge_last_migration_failure')['error_code']);
        } finally {
            remove_filter('query', $deny);
            $wpdb->suppress_errors($previous);
            self::assertTrue(DigiForge\Database\PodSchema::migrateIfNeeded());
        }
        $column = $wpdb->get_row("SHOW COLUMNS FROM {$table} LIKE 'dpi_target'", ARRAY_A);
        self::assertIsArray($column);
        self::assertSame('smallint(5) unsigned', $column['Type']);
    }
}
