<?php
declare(strict_types=1);

final class FinalMigrationAcceptanceTest extends WP_UnitTestCase
{
    public function testRepeatedActivationPreservesAuditRowsWithoutInvalidDefaultAlter(): void
    {
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
        global $wpdb;
        $table = DigiForge\Database\Tables::audit_log();
        $event = 'local_migration_acceptance_'.wp_generate_uuid4();
        self::assertTrue(DigiForge\Security\Logger::write($event, ['local_fixture_only'=>true], 'test', 'migration'));
        $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE event_type=%s", $event), ARRAY_A);
        self::assertIsArray($before);
        $shape = $wpdb->get_row('SHOW CREATE TABLE '.$table, ARRAY_N);
        $queries = [];
        $filter = static function (string $sql) use (&$queries, $table): string {
            if (str_starts_with($sql, 'ALTER TABLE '.$table)) $queries[] = $sql;
            return $sql;
        };
        add_filter('query', $filter);
        try { DigiForge\Core\Activator::activate(); }
        finally { remove_filter('query', $filter); }
        self::assertSame([], $queries, 'Unchanged audit schema must not produce invalid default or destructive ALTER statements.');
        self::assertSame($shape, $wpdb->get_row('SHOW CREATE TABLE '.$table, ARRAY_N));
        self::assertSame($before, $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE event_type=%s", $event), ARRAY_A));
        self::assertSame(DigiForge\Database\MigrationPlan::LATEST, (int)get_option('digiforge_db_schema_version'));
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
    }
}
