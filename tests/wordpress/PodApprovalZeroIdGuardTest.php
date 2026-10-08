<?php
declare(strict_types=1);

final class PodApprovalZeroIdGuardTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testZeroOwnershipIdCannotBeApproved(): void
    {
        $reviewer = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($reviewer);
        $result = (new \DigiForge\POD\BusinessScopeRepository())->approveMapping(0);
        self::assertWPError($result);
        self::assertSame('digiforge_scope_transition', $result->get_error_code());
    }
}
