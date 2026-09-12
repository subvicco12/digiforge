<?php

declare(strict_types=1);

final class MigrationTest extends WP_UnitTestCase
{
    public function testSchemaSevenPreservesOperationalTablesAndCreatesResearchTables(): void
    {
        DigiForge\Core\Activator::activate();

        global $wpdb;
        $columns = $wpdb->get_col('SHOW COLUMNS FROM ' . DigiForge\Database\Tables::jobs(), 0);

        self::assertContains('locked_by', $columns);
        self::assertContains('lease_expires_at', $columns);
        self::assertContains('next_attempt_at', $columns);
        self::assertContains('dead_lettered_at', $columns);
        self::assertSame(DigiForge\Database\Tables::integrations(), $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(DigiForge\Database\Tables::integrations()))));
        self::assertSame(DigiForge\Database\Tables::integration_secrets(), $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(DigiForge\Database\Tables::integration_secrets()))));

        $integrationColumns = $wpdb->get_col('SHOW COLUMNS FROM ' . DigiForge\Database\Tables::integrations(), 0);
        $integrationIndexes = $wpdb->get_col('SHOW INDEX FROM ' . DigiForge\Database\Tables::integrations(), 2);
        $secretIndexes = $wpdb->get_col('SHOW INDEX FROM ' . DigiForge\Database\Tables::integration_secrets(), 2);
        self::assertContains('environment', $integrationColumns);
        self::assertContains('provider_environment_connection', $integrationIndexes);
        self::assertContains('provider_environment_status', $integrationIndexes);
        self::assertContains('integration_secret', $secretIndexes);

        foreach ([
            DigiForge\Database\Tables::research_sources(),
            DigiForge\Database\Tables::research_observations(),
            DigiForge\Database\Tables::research_evidence(),
            DigiForge\Database\Tables::research_candidates(),
            DigiForge\Database\Tables::research_candidate_evidence(),
            DigiForge\Database\Tables::research_reviews(),
        ] as $table) {
            self::assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))));
        }

        $candidateIndexes = $wpdb->get_col('SHOW INDEX FROM ' . DigiForge\Database\Tables::research_candidates(), 2);
        self::assertContains('fingerprint', $candidateIndexes);
        self::assertSame(7, (int) get_option('digiforge_db_schema_version'));
    }

    public function testHealthSnapshotRemainsSafetyLocked(): void
    {
        DigiForge\Core\Activator::activate();

        $snapshot = (new DigiForge\Observability\HealthMonitor())->snapshot();

        self::assertTrue($snapshot['automation_locked']);
        self::assertSame(7, $snapshot['schema']['expected']);
        self::assertSame(7, $snapshot['schema']['current']);
    }
}
