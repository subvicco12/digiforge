<?php
declare(strict_types=1);

final class PodRegistryStoreProgramQueryOutageTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function testStoreAndProgramRegistryOutagesAreDistinctFromMissingConfiguration(): void
    {
        global $wpdb;
        $tables = \DigiForge\Database\Tables::class;
        $now = current_time('mysql', true);
        $key = 'scopeoutage' . wp_rand(100000, 999999);
        self::assertSame(1, $wpdb->insert($tables::businesses(), [
            'business_key' => $key,
            'display_name' => 'Scope outage test',
            'status' => 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        $businessId = (int) $wpdb->insert_id;
        self::assertSame(1, $wpdb->insert($tables::stores(), [
            'business_id' => $businessId,
            'store_key' => $key,
            'display_name' => 'Scope outage store',
            'status' => 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        $storeId = (int) $wpdb->insert_id;
        self::assertSame(1, $wpdb->insert($tables::product_programs(), [
            'business_id' => $businessId,
            'store_id' => $storeId,
            'program_key' => 'PERSONALIZED_POD',
            'status' => 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        $scope = ['business_id' => $key, 'store_id' => $key, 'product_program' => 'PERSONALIZED_POD'];
        $expected = \DigiForge\POD\BusinessScope::resolveConfigured($scope);
        self::assertSame($businessId, $expected['business_id']);
        self::assertSame($storeId, $expected['store_id']);
        foreach (['store' => $tables::stores(), 'product_program' => $tables::product_programs()] as $kind => $table) {
            $filter = static function (string $sql) use ($table): string {
                if (str_contains($sql, 'SELECT * FROM ' . $table . ' WHERE ')) {
                    return 'SELECT * FROM digiforge_test_missing_scope_' . 'registry';
                }
                return $sql;
            };
            add_filter('query', $filter);
            try {
                try {
                    \DigiForge\POD\BusinessScope::resolveConfigured($scope);
                    self::fail('SQL outage must block ' . $kind . ' registry resolution.');
                } catch (\RuntimeException $e) {
                    self::assertSame('POD ' . $kind . ' registry evidence unavailable', $e->getMessage());
                }
            } finally {
                remove_filter('query', $filter);
            }
        }
    }
}
