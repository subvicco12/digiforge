<?php
declare(strict_types=1);

final class PodOwnershipApprovalReadbackFailureTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function testFailedApprovalUpdateDoesNotTriggerConfirmationRead(): void
    {
        $table = \DigiForge\Database\Tables::pod_business_mappings();
        $observed = false;
        $filter = static function (string $sql) use ($table, &$observed): string {
            if (str_contains($sql, 'SELECT * FROM ' . $table . ' WHERE id=')) {
                $observed = true;
            }
            return $sql;
        };
        add_filter('query', $filter);
        try {
            $result = (new \DigiForge\POD\BusinessScopeRepository())->approveMapping(2147483647);
            self::assertWPError($result);
            self::assertSame('digiforge_scope_transition', $result->get_error_code());
            self::assertFalse($observed, 'A rejected approval must not perform success confirmation.');
        } finally {
            remove_filter('query', $filter);
        }
    }
}
