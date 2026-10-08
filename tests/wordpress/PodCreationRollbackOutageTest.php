<?php
declare(strict_types=1);

final class PodCreationRollbackOutageTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testRollbackFailurePreservesPrimaryProviderEvidenceError(): void
    {
        global $wpdb;
        $tables = \DigiForge\Database\Tables::class;
        $now = current_time('mysql', true);
        $key = 'rollbackoutage' . wp_rand(100000, 999999);
        self::assertSame(1, $wpdb->insert($tables::businesses(), [
            'business_key' => $key, 'display_name' => 'Rollback outage business', 'status' => 'ACTIVE',
            'created_at' => $now, 'updated_at' => $now,
        ]));
        $businessId = (int) $wpdb->insert_id;
        self::assertSame(1, $wpdb->insert($tables::stores(), [
            'business_id' => $businessId, 'store_key' => $key, 'display_name' => 'Rollback outage store',
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
                return 'SELECT * FROM digiforge_test_missing_rollback_statement';
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
            ], 'rollback-outage-' . $key);
            self::assertWPError($result);
            self::assertSame('digiforge_scope_evidence_unavailable', $result->get_error_code());
            self::assertSame(1, $rollbacks);
            // The rollback command failed; never infer transaction state or safe retry.
        } finally {
            remove_filter('query', $filter);
        }
    }
}
