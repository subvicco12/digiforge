<?php
declare(strict_types=1);

final class PodApprovalUncertainOutcomeMetadataTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function testApprovalReadbackOutageForbidsRetryAndExternalExecution(): void
    {
        global $wpdb;
        $table = \DigiForge\Database\Tables::pod_business_mappings();
        $now = current_time('mysql', true);
        self::assertSame(1, $wpdb->insert($table, [
            'business_id' => 2147483647, 'store_id' => 2147483647,
            'product_program_id' => 2147483647, 'product_version_id' => 2147483647,
            'provider_mapping_id' => 2147483647, 'state' => 'DRAFT',
            'created_at' => $now, 'updated_at' => $now,
        ]));
        $id = (int) $wpdb->insert_id;
        $filter = static function (string $sql) use ($table, $id): string {
            if (str_contains($sql, 'SELECT * FROM ' . $table . ' WHERE id=' . $id)) {
                return 'SELECT * FROM digiforge_test_missing_approval_readback_metadata';
            }
            return $sql;
        };
        add_filter('query', $filter);
        try {
            $result = (new \DigiForge\POD\BusinessScopeRepository())->approveMapping($id);
            self::assertWPError($result);
            self::assertSame('digiforge_scope_confirmation_unavailable', $result->get_error_code());
            self::assertSame(false, $result->get_error_data()['retry_permitted']);
            self::assertSame(false, $result->get_error_data()['external_execution_authorized']);
        } finally {
            remove_filter('query', $filter);
        }
    }
}
