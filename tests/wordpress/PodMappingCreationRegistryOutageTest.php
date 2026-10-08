<?php
declare(strict_types=1);

final class PodMappingCreationRegistryOutageTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testRegistrySqlOutageRollsBackAndReturnsEvidenceUnavailable(): void
    {
        global $wpdb;
        $businesses = \DigiForge\Database\Tables::businesses();
        $mappings = \DigiForge\Database\Tables::pod_business_mappings();
        $key = 'pod-registry-outage-' . wp_rand(100000, 999999);
        $rollbacks = 0;
        $filter = static function (string $sql) use ($businesses, &$rollbacks): string {
            if (trim($sql) === 'ROLLBACK') {
                ++$rollbacks;
            }
            if (str_contains($sql, 'SELECT * FROM ' . $businesses . ' WHERE business_key=')) {
                return 'SELECT * FROM digiforge_test_missing_creation_registry';
            }
            return $sql;
        };
        add_filter('query', $filter);
        try {
            $result = (new \DigiForge\POD\BusinessScopeRepository())->createMapping([
                'business_id' => 'digiforge_nonexistent_business',
                'store_id' => 'digiforge_nonexistent_store',
                'product_program' => 'PERSONALIZED_POD',
                'product_version_id' => 2147483647,
                'provider_mapping_id' => 2147483647,
            ], $key);
            self::assertWPError($result);
            self::assertSame('digiforge_scope_evidence_unavailable', $result->get_error_code());
            self::assertFalse($result->get_error_data()['retry_permitted']);
            self::assertFalse($result->get_error_data()['external_execution_authorized']);
            self::assertSame(1, $rollbacks);
            self::assertSame(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $mappings . ' WHERE idempotency_key=%s', $key)));
        } finally {
            remove_filter('query', $filter);
        }
    }
}
