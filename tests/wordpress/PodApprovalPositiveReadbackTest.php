<?php
declare(strict_types=1);

final class PodApprovalPositiveReadbackTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function testSuccessfulApprovalReturnsPersistedHumanApprovalEvidence(): void
    {
        global $wpdb;
        $table = \DigiForge\Database\Tables::pod_business_mappings();
        $now = current_time('mysql', true);
        self::assertSame(1, $wpdb->insert($table, [
            'business_id' => 2147483647,
            'store_id' => 2147483647,
            'product_program_id' => 2147483647,
            'product_version_id' => 2147483647,
            'provider_mapping_id' => 2147483647,
            'state' => 'DRAFT',
            'created_at' => $now,
            'updated_at' => $now,
        ]), 'Approval fixture must be created; no silent skip.');
        $id = (int) $wpdb->insert_id;
        $reviewer = get_current_user_id();
        $result = (new \DigiForge\POD\BusinessScopeRepository())->approveMapping($id);
        self::assertIsArray($result);
        self::assertSame($id, (int) $result['id']);
        self::assertSame('APPROVED', $result['state']);
        self::assertSame($reviewer, (int) $result['approved_by']);
        self::assertNotEmpty($result['approved_at']);
        $second = (new \DigiForge\POD\BusinessScopeRepository())->approveMapping($id);
        self::assertWPError($second);
        self::assertSame('digiforge_scope_transition', $second->get_error_code());
    }
}
