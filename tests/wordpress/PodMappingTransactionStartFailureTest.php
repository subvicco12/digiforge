<?php
declare(strict_types=1);

final class PodMappingTransactionStartFailureTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testFailedTransactionStartBlocksRegistryReadsAndOwnershipWrites(): void
    {
        global $wpdb;
        $key = 'pod-start-failure-' . wp_rand(100000, 999999);
        $starts = 0;
        $registryReads = 0;
        $filter = static function (string $sql) use (&$starts, &$registryReads): string {
            if (trim($sql) === 'START TRANSACTION') {
                ++$starts;
                return 'SELECT * FROM digiforge_test_missing_transaction_start';
            }
            if (str_contains($sql, 'SELECT * FROM ' . \DigiForge\Database\Tables::businesses())) {
                ++$registryReads;
            }
            return $sql;
        };
        add_filter('query', $filter);
        try {
            $result = (new \DigiForge\POD\BusinessScopeRepository())->createMapping([
                'business_id' => 'digiforge_missing_business',
                'store_id' => 'digiforge_missing_store',
                'product_program' => 'PERSONALIZED_POD',
                'product_version_id' => 2147483647,
                'provider_mapping_id' => 2147483647,
            ], $key);
            self::assertWPError($result);
            self::assertSame('digiforge_scope_transaction_unavailable', $result->get_error_code());
            self::assertSame(503, $result->get_error_data()['status']);
            self::assertSame(false, $result->get_error_data()['retry_permitted']);
            self::assertSame(false, $result->get_error_data()['external_execution_authorized']);
            self::assertSame(1, $starts);
            self::assertSame(0, $registryReads);
            self::assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . \DigiForge\Database\Tables::pod_business_mappings() . ' WHERE idempotency_key=%s',
                $key
            )));
        } finally {
            remove_filter('query', $filter);
        }
    }
}
