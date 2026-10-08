<?php
declare(strict_types=1);

final class PodReplayFingerprintValidationTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testMatchingKeyWithIncompleteRequestRejectsWithoutTransaction(): void
    {
        global $wpdb;
        $table = \DigiForge\Database\Tables::pod_business_mappings();
        $key = 'incomplete-replay-' . wp_rand(100000, 999999);
        $now = current_time('mysql', true);
        self::assertSame(1, $wpdb->insert($table, [
            'business_id' => 2147483647, 'store_id' => 2147483647,
            'product_program_id' => 2147483647, 'product_version_id' => 2147483647,
            'provider_mapping_id' => 2147483647, 'state' => 'DRAFT',
            'request_fingerprint' => str_repeat('a', 64), 'idempotency_key' => $key,
            'created_at' => $now, 'updated_at' => $now,
        ]));
        $starts = 0;
        $filter = static function (string $sql) use (&$starts): string {
            if (trim($sql) === 'START TRANSACTION') {
                ++$starts;
            }
            return $sql;
        };
        add_filter('query', $filter);
        try {
            $result = (new \DigiForge\POD\BusinessScopeRepository())->createMapping([], $key);
            self::assertWPError($result);
            self::assertSame('digiforge_scope_validation', $result->get_error_code());
            self::assertSame(0, $starts);
        } finally {
            remove_filter('query', $filter);
        }
    }
}
