<?php
declare(strict_types=1);

final class PodOwnershipIdempotencyConflictTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testReusedIdempotencyKeyWithDifferentScopeRejectsBeforeTransaction(): void
    {
        global $wpdb;
        $table = \DigiForge\Database\Tables::pod_business_mappings();
        $key = 'scope-conflict-' . wp_rand(100000, 999999);
        $now = current_time('mysql', true);
        self::assertSame(1, $wpdb->insert($table, [
            'business_id' => 2147483647, 'store_id' => 2147483647,
            'product_program_id' => 2147483647, 'product_version_id' => 2147483647,
            'provider_mapping_id' => 2147483647, 'state' => 'DRAFT',
            'request_fingerprint' => str_repeat('a', 64), 'idempotency_key' => $key,
            'created_at' => $now, 'updated_at' => $now,
        ]));
        $transactions = 0;
        $filter = static function (string $sql) use (&$transactions): string {
            if (trim($sql) === 'START TRANSACTION') {
                ++$transactions;
            }
            return $sql;
        };
        add_filter('query', $filter);
        try {
            $result = (new \DigiForge\POD\BusinessScopeRepository())->createMapping([
                'business_id' => 'other_business', 'store_id' => 'other_store',
                'product_program' => 'PERSONALIZED_POD',
                'product_version_id' => 2147483646, 'provider_mapping_id' => 2147483646,
            ], $key);
            self::assertWPError($result);
            self::assertSame('digiforge_idempotency_conflict', $result->get_error_code());
            self::assertSame(0, $transactions);
        } finally {
            remove_filter('query', $filter);
        }
    }
}
