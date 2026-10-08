<?php
declare(strict_types=1);

final class PodOwnershipApprovalConfirmationFailureTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function testApprovalOfMissingMappingDoesNotSucceed(): void
    {
        $result = (new \DigiForge\POD\BusinessScopeRepository())->approveMapping(2147483647);
        self::assertWPError($result);
        self::assertSame('digiforge_scope_transition', $result->get_error_code());
    }

    public function testMissingAuthenticatedReviewerBlocksApproval(): void
    {
        wp_set_current_user(0);
        $result = (new \DigiForge\POD\BusinessScopeRepository())->approveMapping(2147483647);
        self::assertWPError($result);
        self::assertSame('digiforge_scope_reviewer_required', $result->get_error_code());
    }
}
