<?php
declare(strict_types=1);

final class PodApprovalMissingRowGuardTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testMissingOwnershipCannotBeApproved(): void
    {
        global $wpdb;
        $reviewer = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($reviewer);
        $result = (new \DigiForge\POD\BusinessScopeRepository())->approveMapping(2147483647);
        self::assertWPError($result);
        self::assertSame('digiforge_scope_transition', $result->get_error_code());
        self::assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . \DigiForge\Database\Tables::pod_business_mappings() . ' WHERE id=%d',
            2147483647
        )));
    }
}
