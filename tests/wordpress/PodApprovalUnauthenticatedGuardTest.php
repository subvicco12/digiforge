<?php
declare(strict_types=1);

final class PodApprovalUnauthenticatedGuardTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testAnonymousApprovalCannotMutateDraftOwnership(): void
    {
        global $wpdb;
        $table = \DigiForge\Database\Tables::pod_business_mappings();
        $now = current_time('mysql', true);
        self::assertSame(1, $wpdb->insert($table, [
            'business_id' => 2147483647, 'store_id' => 2147483647,
            'product_program_id' => 2147483647, 'product_version_id' => 2147483647,
            'provider_mapping_id' => 2147483647, 'state' => 'DRAFT',
            'request_fingerprint' => str_repeat('a', 64),
            'idempotency_key' => 'anonymous-approval-' . wp_rand(100000, 999999),
            'created_at' => $now, 'updated_at' => $now,
        ]));
        $id = (int) $wpdb->insert_id;
        wp_set_current_user(0);
        $result = (new \DigiForge\POD\BusinessScopeRepository())->approveMapping($id);
        self::assertWPError($result);
        self::assertSame('digiforge_scope_reviewer_required', $result->get_error_code());
        $row = $wpdb->get_row($wpdb->prepare('SELECT state,approved_by FROM ' . $table . ' WHERE id=%d', $id), ARRAY_A);
        self::assertSame('DRAFT', $row['state']);
        self::assertSame(0, (int) $row['approved_by']);
    }
}
