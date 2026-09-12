<?php

declare(strict_types=1);

final class MigrationTest extends WP_UnitTestCase
{
    public function testSchemaEightPreservesOperationalTablesAndCreatesAiGovernanceTables(): void
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

        foreach ([
            DigiForge\Database\Tables::research_sources(), DigiForge\Database\Tables::research_observations(), DigiForge\Database\Tables::research_evidence(),
            DigiForge\Database\Tables::research_candidates(), DigiForge\Database\Tables::research_candidate_evidence(), DigiForge\Database\Tables::research_reviews(),
            DigiForge\Database\Tables::ai_tasks(), DigiForge\Database\Tables::ai_models(), DigiForge\Database\Tables::ai_prompts(), DigiForge\Database\Tables::ai_prompt_versions(),
            DigiForge\Database\Tables::ai_runs(), DigiForge\Database\Tables::ai_outputs(), DigiForge\Database\Tables::ai_usage(), DigiForge\Database\Tables::ai_reviews(),
        ] as $table) {
            self::assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))));
        }

        $runIndexes = $wpdb->get_col('SHOW INDEX FROM ' . DigiForge\Database\Tables::ai_runs(), 2);
        $promptIndexes = $wpdb->get_col('SHOW INDEX FROM ' . DigiForge\Database\Tables::ai_prompt_versions(), 2);
        self::assertContains('state_updated', $runIndexes);
        self::assertContains('prompt_version', $promptIndexes);
        self::assertSame(8, (int) get_option('digiforge_db_schema_version'));
        self::assertSame('8', (string) get_option('digiforge_db_version'));
    }

    public function testReactivationAtSchemaEightDoesNotChangeSchema(): void
    {
        DigiForge\Core\Activator::activate();
        global $wpdb;
        $before = $wpdb->get_results('SHOW CREATE TABLE ' . DigiForge\Database\Tables::ai_runs(), ARRAY_N);
        DigiForge\Core\Activator::activate();
        $after = $wpdb->get_results('SHOW CREATE TABLE ' . DigiForge\Database\Tables::ai_runs(), ARRAY_N);
        self::assertSame($before, $after);
        self::assertSame('', (string) $wpdb->last_error);
    }

    public function testHealthSnapshotRemainsSafetyLocked(): void
    {
        DigiForge\Core\Activator::activate();
        $snapshot = (new DigiForge\Observability\HealthMonitor())->snapshot();
        self::assertTrue($snapshot['automation_locked']);
        self::assertSame(8, $snapshot['schema']['expected']);
        self::assertSame(8, $snapshot['schema']['current']);
    }
}
