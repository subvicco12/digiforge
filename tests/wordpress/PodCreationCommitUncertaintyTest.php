<?php
declare(strict_types=1);

final class PodCreationCommitUncertaintyTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testMissingProviderMappingRejectsBeforeCommit(): void
    {
        global $wpdb;
        $tables = \DigiForge\Database\Tables::class;
        $now = current_time('mysql', true);
        $key = 'commitoutage' . wp_rand(100000, 999999);
        self::assertSame(1, $wpdb->insert($tables::businesses(), [
            'business_key' => $key, 'display_name' => 'Commit test business', 'status' => 'ACTIVE',
            'created_at' => $now, 'updated_at' => $now,
        ]));
        $businessId = (int) $wpdb->insert_id;
        self::assertSame(1, $wpdb->insert($tables::stores(), [
            'business_id' => $businessId, 'store_key' => $key, 'display_name' => 'Commit test store',
            'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
        ]));
        $storeId = (int) $wpdb->insert_id;
        self::assertSame(1, $wpdb->insert($tables::product_programs(), [
            'business_id' => $businessId, 'store_id' => $storeId,
            'program_key' => 'PERSONALIZED_POD', 'status' => 'ACTIVE',
            'created_at' => $now, 'updated_at' => $now,
        ]));
        $commits = 0;
        $filter = static function (string $sql) use (&$commits): string {
            if (trim($sql) === 'COMMIT') {
                ++$commits;
                return 'SELECT * FROM digiforge_test_missing_commit_table';
            }
            return $sql;
        };
        add_filter('query', $filter);
        try {
            $scope = ['business_id' => $key, 'store_id' => $key, 'product_program' => 'PERSONALIZED_POD'];
            $resolved = \DigiForge\POD\BusinessScope::resolveConfigured($scope);
            self::assertSame($businessId, $resolved['business_id']);
            // Invalid provider mapping must reject before the COMMIT boundary.
            $result = (new \DigiForge\POD\BusinessScopeRepository())->createMapping(
                $scope + ['product_version_id' => 2147483647, 'provider_mapping_id' => 2147483647],
                'commit-uncertainty-' . $key
            );
            self::assertWPError($result);
            self::assertSame('digiforge_scope_relationship', $result->get_error_code());
            self::assertSame(0, $commits, 'Missing provider mapping must reject before COMMIT.');
        } finally {
            remove_filter('query', $filter);
        }
    }
}
