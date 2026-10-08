<?php
declare(strict_types=1);

final class PodCreationPostInsertConfirmationTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testFailedPostInsertReadbackRollsBackAndForbidsExternalExecution(): void
    {
        global $wpdb;
        $tables = \DigiForge\Database\Tables::class;
        $now = current_time('mysql', true);
        $key = 'confirmoutage' . wp_rand(100000, 999999);
        self::assertSame(1, $wpdb->insert($tables::businesses(), [
            'business_key' => $key, 'display_name' => 'Confirmation test business',
            'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
        ]));
        $businessId = (int) $wpdb->insert_id;
        self::assertSame(1, $wpdb->insert($tables::stores(), [
            'business_id' => $businessId, 'store_key' => $key, 'display_name' => 'Confirmation test store',
            'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
        ]));
        $storeId = (int) $wpdb->insert_id;
        self::assertSame(1, $wpdb->insert($tables::product_programs(), [
            'business_id' => $businessId, 'store_id' => $storeId,
            'program_key' => 'PERSONALIZED_POD', 'status' => 'ACTIVE',
            'created_at' => $now, 'updated_at' => $now,
        ]));
        $productVersionId = 2147483647;
        self::assertSame(1, $wpdb->insert($tables::pod_mappings(), [
            'product_version_id' => $productVersionId, 'production_plan_id' => 2147483647,
            'provider' => 'printify', 'environment' => 'sandbox',
            'provider_product_key' => $key, 'provider_variant_key' => '',
            'mapping_version' => 'v1', 'state' => 'DRAFT',
            'created_at' => $now, 'updated_at' => $now,
        ]));
        $providerMappingId = (int) $wpdb->insert_id;
        $rollbacks = 0;
        $readbacks = 0;
        $filter = static function (string $sql) use ($tables, &$rollbacks, &$readbacks): string {
            if (trim($sql) === 'ROLLBACK') {
                ++$rollbacks;
            }
            if (str_contains($sql, 'SELECT * FROM ' . $tables::pod_business_mappings() . ' WHERE id=')) {
                ++$readbacks;
                return 'SELECT * FROM digiforge_test_missing_postinsert_confirmation';
            }
            return $sql;
        };
        add_filter('query', $filter);
        try {
            $idempotencyKey = 'confirm-outage-' . $key;
            $result = (new \DigiForge\POD\BusinessScopeRepository())->createMapping([
                'business_id' => $key, 'store_id' => $key,
                'product_program' => 'PERSONALIZED_POD',
                'product_version_id' => $productVersionId,
                'provider_mapping_id' => $providerMappingId,
            ], $idempotencyKey);
            self::assertWPError($result);
            self::assertSame('digiforge_scope_confirmation_unavailable', $result->get_error_code());
            self::assertSame(false, $result->get_error_data()['retry_permitted']);
            self::assertSame(false, $result->get_error_data()['external_execution_authorized']);
            self::assertSame(1, $readbacks);
            self::assertSame(1, $rollbacks);
            self::assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $tables::pod_business_mappings() . ' WHERE idempotency_key=%s',
                $idempotencyKey
            )));
        } finally {
            remove_filter('query', $filter);
        }
    }
}
