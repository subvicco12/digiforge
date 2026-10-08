<?php
declare(strict_types=1);

final class PodOwnershipExactReplayTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testExactIdempotencyReplayReturnsOriginalOwnerWithoutTransaction(): void
    {
        global $wpdb;
        $input = [
            'business_id' => 'other_business', 'store_id' => 'other_store',
            'product_program' => 'PERSONALIZED_POD',
            'product_version_id' => 2147483646, 'provider_mapping_id' => 2147483646,
        ];
        $repository = new \DigiForge\POD\BusinessScopeRepository();
        $method = new ReflectionMethod($repository, 'requestFingerprint');
        $fingerprint = $method->invoke($repository, $input);
        self::assertIsString($fingerprint);
        $table = \DigiForge\Database\Tables::pod_business_mappings();
        $key = 'exact-replay-' . wp_rand(100000, 999999);
        $now = current_time('mysql', true);
        self::assertSame(1, $wpdb->insert($table, [
            'business_id' => 2147483647, 'store_id' => 2147483647,
            'product_program_id' => 2147483647, 'product_version_id' => 2147483647,
            'provider_mapping_id' => 2147483647, 'state' => 'DRAFT',
            'request_fingerprint' => $fingerprint, 'idempotency_key' => $key,
            'created_at' => $now, 'updated_at' => $now,
        ]));
        $id = (int) $wpdb->insert_id;
        $transactions = 0;
        $filter = static function (string $sql) use (&$transactions): string {
            if (trim($sql) === 'START TRANSACTION') {
                ++$transactions;
            }
            return $sql;
        };
        add_filter('query', $filter);
        try {
            $result = $repository->createMapping($input, $key);
            self::assertIsArray($result);
            self::assertSame($id, (int) $result['id']);
            self::assertSame(0, $transactions);
        } finally {
            remove_filter('query', $filter);
        }
    }
}
