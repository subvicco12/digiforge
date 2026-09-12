<?php

declare(strict_types=1);

final class MigrationTest extends WP_UnitTestCase
{
    private function productionTables(): array
    {
        return [
            DigiForge\Database\Tables::asset_specs(), DigiForge\Database\Tables::production_plans(), DigiForge\Database\Tables::production_plan_assets(),
            DigiForge\Database\Tables::production_intents(), DigiForge\Database\Tables::asset_revisions(), DigiForge\Database\Tables::production_qa(),
            DigiForge\Database\Tables::release_bundles(), DigiForge\Database\Tables::release_bundle_revisions(),
        ];
    }

    private function podTables(): array
    {
        return [
            DigiForge\Database\Tables::pod_catalog(), DigiForge\Database\Tables::pod_mappings(), DigiForge\Database\Tables::pod_print_areas(),
            DigiForge\Database\Tables::personalization_schemas(), DigiForge\Database\Tables::personalization_bindings(), DigiForge\Database\Tables::pod_provider_intents(),
            DigiForge\Database\Tables::pod_cost_snapshots(), DigiForge\Database\Tables::pod_readiness_reviews(),
        ];
    }

    private function listingTables(): array
    {
        return [
            DigiForge\Database\Tables::listings(), DigiForge\Database\Tables::listing_seo(), DigiForge\Database\Tables::listing_media(),
            DigiForge\Database\Tables::listing_pod_bindings(), DigiForge\Database\Tables::etsy_draft_packages(), DigiForge\Database\Tables::etsy_intents(),
            DigiForge\Database\Tables::listing_readiness_reviews(),
        ];
    }

    public function testSchemaElevenPreservesOperationalTablesAndCreatesCurrentTables(): void
    {
        DigiForge\Core\Activator::activate();
        global $wpdb;
        $columns = $wpdb->get_col('SHOW COLUMNS FROM ' . DigiForge\Database\Tables::jobs(), 0);
        foreach (['locked_by','lease_expires_at','next_attempt_at','dead_lettered_at'] as $column) self::assertContains($column, $columns);
        foreach (array_merge([
            DigiForge\Database\Tables::integrations(), DigiForge\Database\Tables::integration_secrets(),
            DigiForge\Database\Tables::research_sources(), DigiForge\Database\Tables::research_observations(), DigiForge\Database\Tables::research_evidence(),
            DigiForge\Database\Tables::research_candidates(), DigiForge\Database\Tables::research_candidate_evidence(), DigiForge\Database\Tables::research_reviews(),
            DigiForge\Database\Tables::ai_tasks(), DigiForge\Database\Tables::ai_models(), DigiForge\Database\Tables::ai_prompts(), DigiForge\Database\Tables::ai_prompt_versions(),
            DigiForge\Database\Tables::ai_runs(), DigiForge\Database\Tables::ai_outputs(), DigiForge\Database\Tables::ai_usage(), DigiForge\Database\Tables::ai_reviews(),
        ], $this->productionTables(), $this->podTables(), $this->listingTables()) as $table) {
            self::assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))));
        }
        self::assertSame(11, (int) get_option('digiforge_db_schema_version'));
        self::assertSame('11', (string) get_option('digiforge_db_version'));
    }

    public function testSchemaTenUpgradeCreatesOnlyBatchEightTables(): void
    {
        DigiForge\Core\Activator::activate();
        global $wpdb;
        $podBefore = $wpdb->get_row('SHOW CREATE TABLE ' . DigiForge\Database\Tables::pod_mappings(), ARRAY_N);
        self::assertIsArray($podBefore);
        foreach ($this->listingTables() as $table) $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($table) . '`');
        update_option('digiforge_db_schema_version', 10, false);
        update_option('digiforge_db_version', '10', false);
        $wpdb->last_error = '';

        self::assertTrue(DigiForge\Database\ListingSchema::migrateIfNeeded());
        (new DigiForge\Database\Migrator())->maybe_migrate();

        foreach ($this->listingTables() as $table) {
            self::assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))));
        }
        $podAfter = $wpdb->get_row('SHOW CREATE TABLE ' . DigiForge\Database\Tables::pod_mappings(), ARRAY_N);
        self::assertSame($podBefore, $podAfter);
        self::assertSame('', (string) $wpdb->last_error);
        self::assertSame(11, (int) get_option('digiforge_db_schema_version'));
        self::assertSame('11', (string) get_option('digiforge_db_version'));
    }

    public function testSchemaEightUpgradeReachesCurrentWithoutChangingLegacyAiTable(): void
    {
        DigiForge\Core\Activator::activate();
        global $wpdb;
        $legacyTable = DigiForge\Database\Tables::ai_runs();
        $legacyBefore = $wpdb->get_row('SHOW CREATE TABLE ' . $legacyTable, ARRAY_N);
        self::assertIsArray($legacyBefore);
        foreach (array_merge($this->productionTables(), $this->podTables(), $this->listingTables()) as $table) $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($table) . '`');
        update_option('digiforge_db_schema_version', 8, false);
        update_option('digiforge_db_version', '8', false);
        $wpdb->last_error = '';

        self::assertTrue(DigiForge\Database\ListingSchema::migrateIfNeeded());
        self::assertTrue(DigiForge\Database\PodSchema::migrateIfNeeded());
        self::assertTrue(DigiForge\Database\ProductionSchema::migrateIfNeeded());
        (new DigiForge\Database\Migrator())->maybe_migrate();

        foreach (array_merge($this->productionTables(), $this->podTables(), $this->listingTables()) as $table) {
            self::assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))));
        }
        $legacyAfter = $wpdb->get_row('SHOW CREATE TABLE ' . $legacyTable, ARRAY_N);
        self::assertSame($legacyBefore, $legacyAfter);
        self::assertSame('', (string) $wpdb->last_error);
        self::assertSame(11, (int) get_option('digiforge_db_schema_version'));
        self::assertSame('11', (string) get_option('digiforge_db_version'));
    }

    public function testReactivationAtSchemaElevenDoesNotChangeSchema(): void
    {
        DigiForge\Core\Activator::activate();
        global $wpdb;
        $before = $wpdb->get_row('SHOW CREATE TABLE ' . DigiForge\Database\Tables::listings(), ARRAY_N);
        DigiForge\Core\Activator::activate();
        $after = $wpdb->get_row('SHOW CREATE TABLE ' . DigiForge\Database\Tables::listings(), ARRAY_N);
        self::assertSame($before, $after);
        self::assertSame('', (string) $wpdb->last_error);
    }

    public function testHealthSnapshotRemainsSafetyLocked(): void
    {
        DigiForge\Core\Activator::activate();
        $snapshot = (new DigiForge\Observability\HealthMonitor())->snapshot();
        self::assertTrue($snapshot['automation_locked']);
        self::assertSame(11, $snapshot['schema']['expected']);
        self::assertSame(11, $snapshot['schema']['current']);
    }
}
