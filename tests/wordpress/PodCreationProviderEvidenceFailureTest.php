<?php
declare(strict_types=1);

final class PodCreationProviderEvidenceFailureTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testProviderMappingSqlFailureRollsBackWithoutCreatingOwnership(): void
    {
        global $wpdb;
        $tables = \DigiForge\Database\Tables::class;
        $now = current_time('mysql', true);
        $key = 'provideroutage' . wp_rand(100000, 999999);
        self::assertSame(1, $wpdb->insert($tables::businesses(), [
            'business_key' => $key, 'display_name' => 'Provider outage business', 'status' => 'ACTIVE',
            'created_at' => $now, 'updated_at' => $now,
        ]));
        $businessId = (int) $wpdb->insert_id;
        self::assertSame(1, $wpdb->insert($tables::stores(), [
            'business_id' => $businessId, 'store_key' => $key, 'display_name' => 'Provider outage store',
            'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
        ]));
        $storeId = (int) $wpdb->insert_id;
        self::assertSame(1, $wpdb->insert($tables::product_programs(), [
            'business_id' => $businessId, 'store_id' => $storeId, 'program_key' => 'PERSONALIZED_POD',
            'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
        ]));
        $rollbacks = 0;
        $filter = static function (string $sql) use ($tables, &$rollbacks): string {
            if (trim($sql) === 'ROLLBACK') {
                ++$rollbacks;
            }
            if (str_contains($sql, 'SELECT product_version_id FROM ' . $tables::pod_mappings() . ' WHERE id=')) {
                return 'SELECT * FROM digiforge_test_missing_provider_evidence';
            }
            return $sql;
        };
        add_filter('query', $filter);
        try {
            $result = (new \DigiForge\POD\BusinessScopeRepository())->createMapping([
                'business_id' => $key, 'store_id' => $key, 'product_program' => 'PERSONALIZED_POD',
                'product_version_id' => 2147483647, 'provider_mapping_id' => 2147483647,
            ], 'provider-evidence-' . $key);
            self::assertWPError($result);
            self::assertSame('digiforge_scope_evidence_unavailable', $result->get_error_code());
            self::assertSame(1, $rollbacks);
            self::assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $tables::pod_business_mappings() . ' WHERE idempotency_key=%s',
                'provider-evidence-' . $key
            )));
        } finally {
            remove_filter('query', $filter);
        }
    }
}
