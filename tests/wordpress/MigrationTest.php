<?php

declare(strict_types=1);

final class MigrationTest extends WP_UnitTestCase
{
    private function productionTables(): array
    {
        return [DigiForge\Database\Tables::asset_specs(), DigiForge\Database\Tables::production_plans(), DigiForge\Database\Tables::production_plan_assets(), DigiForge\Database\Tables::production_intents(), DigiForge\Database\Tables::asset_revisions(), DigiForge\Database\Tables::production_qa(), DigiForge\Database\Tables::release_bundles(), DigiForge\Database\Tables::release_bundle_revisions()];
    }
    private function podTables(): array
    {
        return [DigiForge\Database\Tables::pod_execution_nonces(), DigiForge\Database\Tables::pod_execution_receipts(), DigiForge\Database\Tables::pod_execution_failures(), DigiForge\Database\Tables::pod_catalog(), DigiForge\Database\Tables::pod_mappings(), DigiForge\Database\Tables::pod_print_areas(), DigiForge\Database\Tables::personalization_schemas(), DigiForge\Database\Tables::personalization_bindings(), DigiForge\Database\Tables::pod_provider_intents(), DigiForge\Database\Tables::pod_cost_snapshots(), DigiForge\Database\Tables::pod_readiness_reviews()];
    }
    private function businessScopeTables(): array
    {
        return [DigiForge\Database\Tables::businesses(), DigiForge\Database\Tables::stores(), DigiForge\Database\Tables::product_programs(), DigiForge\Database\Tables::pod_business_mappings()];
    }
    private function listingTables(): array
    {
        return [DigiForge\Database\Tables::listings(), DigiForge\Database\Tables::listing_seo(), DigiForge\Database\Tables::listing_media(), DigiForge\Database\Tables::listing_pod_bindings(), DigiForge\Database\Tables::etsy_draft_packages(), DigiForge\Database\Tables::etsy_intents(), DigiForge\Database\Tables::listing_readiness_reviews()];
    }
    private function orderTables(): array
    {
        return [DigiForge\Database\Tables::orders(), DigiForge\Database\Tables::order_line_items(), DigiForge\Database\Tables::personalization_submissions(), DigiForge\Database\Tables::fulfillment_plans(), DigiForge\Database\Tables::fulfillment_intents(), DigiForge\Database\Tables::fulfillment_readiness_reviews()];
    }
    private function financeTables(): array
    {
        return [DigiForge\Database\Tables::finance_ledger(), DigiForge\Database\Tables::fx_snapshots(), DigiForge\Database\Tables::tax_classifications(), DigiForge\Database\Tables::finance_periods(), DigiForge\Database\Tables::analytics_snapshots(), DigiForge\Database\Tables::operational_alerts(), DigiForge\Database\Tables::finance_intents()];
    }
    private function finishBusinessScopeUpgrade(): void
    {
        self::assertTrue(DigiForge\Database\BusinessScopeInstaller::migrateIfNeeded());
        (new DigiForge\Database\Migrator())->maybe_migrate();
    }

    public function testSchemaFifteenPreservesOperationalTablesAndCreatesCurrentTables(): void
    {
        DigiForge\Core\Activator::activate();
        global $wpdb;
        $columns = $wpdb->get_col('SHOW COLUMNS FROM ' . DigiForge\Database\Tables::jobs(), 0);
        foreach (['locked_by','lease_expires_at','next_attempt_at','dead_lettered_at'] as $column) self::assertContains($column, $columns);
        foreach (array_merge([DigiForge\Database\Tables::integrations(), DigiForge\Database\Tables::integration_secrets(), DigiForge\Database\Tables::research_sources(), DigiForge\Database\Tables::research_observations(), DigiForge\Database\Tables::research_evidence(), DigiForge\Database\Tables::research_candidates(), DigiForge\Database\Tables::research_candidate_evidence(), DigiForge\Database\Tables::research_reviews(), DigiForge\Database\Tables::ai_tasks(), DigiForge\Database\Tables::ai_models(), DigiForge\Database\Tables::ai_prompts(), DigiForge\Database\Tables::ai_prompt_versions(), DigiForge\Database\Tables::ai_runs(), DigiForge\Database\Tables::ai_outputs(), DigiForge\Database\Tables::ai_usage(), DigiForge\Database\Tables::ai_reviews()], $this->productionTables(), $this->podTables(), $this->businessScopeTables(), $this->listingTables(), $this->orderTables(), $this->financeTables()) as $table) self::assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))));
        self::assertSame(15, (int) get_option('digiforge_db_schema_version'));
        self::assertSame('15', (string) get_option('digiforge_db_version'));
    }

    public function testSchemaFourteenUpgradeAddsFulfillmentIntentPlanEvidence(): void
    {
        DigiForge\Core\Activator::activate(); global $wpdb;
        $table=DigiForge\Database\Tables::fulfillment_intents();
        $wpdb->query("ALTER TABLE {$table} DROP COLUMN fulfillment_plan_payload_hash");
        update_option('digiforge_db_schema_version',14,false); update_option('digiforge_db_version','14',false);
        self::assertTrue(DigiForge\Database\OrderSchema::migrateIfNeeded()); (new DigiForge\Database\Migrator())->maybe_migrate();
        self::assertContains('fulfillment_plan_payload_hash',$wpdb->get_col('SHOW COLUMNS FROM '.$table,0));
        self::assertSame(15,(int)get_option('digiforge_db_schema_version')); self::assertSame('15',(string)get_option('digiforge_db_version'));
    }

    public function testSchemaTwelveUpgradeCreatesFinanceAndBusinessScopeTables(): void
    {
        DigiForge\Core\Activator::activate(); global $wpdb;
        $orderBefore = $wpdb->get_row('SHOW CREATE TABLE ' . DigiForge\Database\Tables::orders(), ARRAY_N); self::assertIsArray($orderBefore);
        foreach (array_merge($this->financeTables(), $this->businessScopeTables()) as $table) $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($table) . '`');
        update_option('digiforge_db_schema_version', 12, false); update_option('digiforge_db_version', '12', false); $wpdb->last_error = '';
        self::assertTrue(DigiForge\Database\FinanceSchema::migrateIfNeeded()); $this->finishBusinessScopeUpgrade();
        foreach (array_merge($this->financeTables(), $this->businessScopeTables()) as $table) self::assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))));
        self::assertSame($orderBefore, $wpdb->get_row('SHOW CREATE TABLE ' . DigiForge\Database\Tables::orders(), ARRAY_N)); self::assertSame('', (string) $wpdb->last_error); self::assertSame(15, (int) get_option('digiforge_db_schema_version')); self::assertSame('15', (string) get_option('digiforge_db_version'));
    }

    public function testSchemaElevenUpgradeCreatesBatchNineThenBatchTenThenBusinessScopeTables(): void
    {
        DigiForge\Core\Activator::activate(); global $wpdb;
        $listingBefore = $wpdb->get_row('SHOW CREATE TABLE ' . DigiForge\Database\Tables::listings(), ARRAY_N); self::assertIsArray($listingBefore);
        foreach (array_merge($this->orderTables(), $this->financeTables(), $this->businessScopeTables()) as $table) $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($table) . '`');
        update_option('digiforge_db_schema_version', 11, false); update_option('digiforge_db_version', '11', false); $wpdb->last_error = '';
        self::assertTrue(DigiForge\Database\OrderSchema::migrateIfNeeded()); self::assertTrue(DigiForge\Database\FinanceSchema::migrateIfNeeded()); $this->finishBusinessScopeUpgrade();
        foreach (array_merge($this->orderTables(), $this->financeTables(), $this->businessScopeTables()) as $table) self::assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))));
        self::assertSame($listingBefore, $wpdb->get_row('SHOW CREATE TABLE ' . DigiForge\Database\Tables::listings(), ARRAY_N)); self::assertSame('', (string) $wpdb->last_error); self::assertSame(15, (int) get_option('digiforge_db_schema_version')); self::assertSame('15', (string) get_option('digiforge_db_version'));
    }

    public function testSchemaEightUpgradeReachesCurrentWithoutChangingLegacyAiTable(): void
    {
        DigiForge\Core\Activator::activate(); global $wpdb;
        $legacyTable = DigiForge\Database\Tables::ai_runs(); $legacyBefore = $wpdb->get_row('SHOW CREATE TABLE ' . $legacyTable, ARRAY_N); self::assertIsArray($legacyBefore);
        foreach (array_merge($this->productionTables(), $this->podTables(), $this->listingTables(), $this->orderTables(), $this->financeTables(), $this->businessScopeTables()) as $table) $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($table) . '`');
        update_option('digiforge_db_schema_version', 8, false); update_option('digiforge_db_version', '8', false); $wpdb->last_error = '';
        self::assertTrue(DigiForge\Database\ProductionSchema::migrateIfNeeded()); self::assertTrue(DigiForge\Database\PodSchema::migrateIfNeeded()); self::assertTrue(DigiForge\Database\ListingSchema::migrateIfNeeded()); self::assertTrue(DigiForge\Database\OrderSchema::migrateIfNeeded()); self::assertTrue(DigiForge\Database\FinanceSchema::migrateIfNeeded()); $this->finishBusinessScopeUpgrade();
        foreach (array_merge($this->productionTables(), $this->podTables(), $this->listingTables(), $this->orderTables(), $this->financeTables(), $this->businessScopeTables()) as $table) self::assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))));
        self::assertSame($legacyBefore, $wpdb->get_row('SHOW CREATE TABLE ' . $legacyTable, ARRAY_N)); self::assertSame('', (string) $wpdb->last_error); self::assertSame(15, (int) get_option('digiforge_db_schema_version')); self::assertSame('15', (string) get_option('digiforge_db_version'));
    }

    public function testReactivationAtSchemaFifteenDoesNotChangeSchema(): void
    {
        DigiForge\Core\Activator::activate(); global $wpdb;
        $before = $wpdb->get_row('SHOW CREATE TABLE ' . DigiForge\Database\Tables::finance_ledger(), ARRAY_N); $scopeBefore = $wpdb->get_row('SHOW CREATE TABLE ' . DigiForge\Database\Tables::pod_business_mappings(), ARRAY_N);
        DigiForge\Core\Activator::activate();
        self::assertSame($before, $wpdb->get_row('SHOW CREATE TABLE ' . DigiForge\Database\Tables::finance_ledger(), ARRAY_N)); self::assertSame($scopeBefore, $wpdb->get_row('SHOW CREATE TABLE ' . DigiForge\Database\Tables::pod_business_mappings(), ARRAY_N)); self::assertSame('', (string) $wpdb->last_error);
    }

    public function testHealthSnapshotRemainsSafetyLocked(): void
    {
        DigiForge\Core\Activator::activate(); $snapshot = (new DigiForge\Observability\HealthMonitor())->snapshot(); self::assertTrue($snapshot['automation_locked']); self::assertSame(15, $snapshot['schema']['expected']); self::assertSame(15, $snapshot['schema']['current']);
    }
}
