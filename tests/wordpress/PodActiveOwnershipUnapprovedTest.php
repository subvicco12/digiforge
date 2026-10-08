<?php
declare(strict_types=1);

final class PodActiveOwnershipUnapprovedTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testUnapprovedOwnershipCannotAuthorizeExecution(): void
    {
        global $wpdb;
        $table = \DigiForge\Database\Tables::pod_business_mappings();
        $key = 'unapproved-' . wp_rand(100000, 999999);
        $now = current_time('mysql', true);
        self::assertSame(1, $wpdb->insert($table, [
            'business_id' => 2147483647, 'store_id' => 2147483647,
            'product_program_id' => 2147483647, 'product_version_id' => 2147483647,
            'provider_mapping_id' => 2147483647, 'state' => 'DRAFT',
            'request_fingerprint' => str_repeat('a', 64), 'idempotency_key' => $key,
            'created_at' => $now, 'updated_at' => $now,
        ]));
        $result = (new \DigiForge\POD\BusinessScopeRepository())->assertActiveOwnershipForMapping(2147483647);
        self::assertWPError($result);
        self::assertSame('digiforge_scope_inactive', $result->get_error_code());
    }
}
