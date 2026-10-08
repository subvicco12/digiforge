<?php
declare(strict_types=1);

final class PodMappingIdempotencyReadFailureTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testIdempotencyQueryFailurePreventsTransactionAndRegistryReads(): void
    {
        global $wpdb;
        $table = \DigiForge\Database\Tables::pod_business_mappings();
        $reads = 0;
        $transactions = 0;
        $registryReads = 0;
        $filter = static function (string $sql) use ($table, &$reads, &$transactions, &$registryReads): string {
            if (str_contains($sql, 'SELECT * FROM ' . $table . ' WHERE idempotency_key=')) {
                ++$reads;
                return 'SELECT * FROM digiforge_test_missing_idempotency_table';
            }
            if (trim($sql) === 'START TRANSACTION') {
                ++$transactions;
            }
            if (str_contains($sql, 'SELECT * FROM ' . \DigiForge\Database\Tables::businesses() . ' WHERE ')) {
                ++$registryReads;
            }
            return $sql;
        };
        add_filter('query', $filter);
        try {
            $result = (new \DigiForge\POD\BusinessScopeRepository())->createMapping([
                'business_id' => 'missing_business',
                'store_id' => 'missing_store',
                'product_program' => 'PERSONALIZED_POD',
                'product_version_id' => 2147483647,
                'provider_mapping_id' => 2147483647,
            ], 'idempotency-read-outage-' . wp_rand(100000, 999999));
            self::assertWPError($result);
            self::assertSame('digiforge_idempotency_evidence_unavailable', $result->get_error_code());
            self::assertSame(503, $result->get_error_data()['status']);
            self::assertSame(false, $result->get_error_data()['retry_permitted']);
            self::assertSame(false, $result->get_error_data()['external_execution_authorized']);
            self::assertSame(1, $reads);
            self::assertSame(0, $transactions);
            self::assertSame(0, $registryReads);
        } finally {
            remove_filter('query', $filter);
        }
    }
}
