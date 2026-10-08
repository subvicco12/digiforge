<?php
declare(strict_types=1);

final class PodApprovalRepeatGuardTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testApprovedOwnershipCannotBeApprovedAgain(): void
    {
        global $wpdb;
        $table = \DigiForge\Database\Tables::pod_business_mappings();
        $reviewer = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($reviewer);
        $now = current_time('mysql', true);
        self::assertSame(1, $wpdb->insert($table, [
            'business_id' => 2147483647, 'store_id' => 2147483647,
            'product_program_id' => 2147483647, 'product_version_id' => 2147483647,
            'provider_mapping_id' => 2147483647, 'state' => 'APPROVED',
            'approved_by' => $reviewer, 'approved_at' => $now,
            'request_fingerprint' => str_repeat('a', 64),
            'idempotency_key' => 'approved-repeat-' . wp_rand(100000, 999999),
            'created_at' => $now, 'updated_at' => $now,
        ]));
        $id = (int) $wpdb->insert_id;
        $result = (new \DigiForge\POD\BusinessScopeRepository())->approveMapping($id);
        self::assertWPError($result);
        self::assertSame('digiforge_scope_transition', $result->get_error_code());
        self::assertSame($reviewer, (int) $wpdb->get_var($wpdb->prepare(
            'SELECT approved_by FROM ' . $table . ' WHERE id=%d', $id
        )));
    }
}
