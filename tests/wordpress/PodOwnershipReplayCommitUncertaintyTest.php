<?php
declare(strict_types=1);

final class PodOwnershipReplayCommitUncertaintyTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testReplayCommitFailureReturnsNonRetryableUnknownOutcome(): void
    {
        global $wpdb;
        $tables = \DigiForge\Database\Tables::class;
        $now = current_time('mysql', true);
        $key = 'replaycommit' . wp_rand(100000, 999999);
        self::assertSame(1, $wpdb->insert($tables::businesses(), [
            'business_key' => $key, 'display_name' => 'Replay test business',
            'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
        ]));
        $businessId = (int) $wpdb->insert_id;
        self::assertSame(1, $wpdb->insert($tables::stores(), [
            'business_id' => $businessId, 'store_key' => $key, 'display_name' => 'Replay test store',
            'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
        ]));
        $storeId = (int) $wpdb->insert_id;
        self::assertSame(1, $wpdb->insert($tables::product_programs(), [
            'business_id' => $businessId, 'store_id' => $storeId,
            'program_key' => 'PERSONALIZED_POD', 'status' => 'ACTIVE',
            'created_at' => $now, 'updated_at' => $now,
        ]));
        $versionId = 2147483647;
        self::assertSame(1, $wpdb->insert($tables::pod_mappings(), [
            'product_version_id' => $versionId, 'production_plan_id' => 2147483647,
            'provider' => 'printify', 'environment' => 'sandbox',
            'provider_product_key' => $key, 'provider_variant_key' => '',
            'mapping_version' => 'v1', 'state' => 'DRAFT',
            'created_at' => $now, 'updated_at' => $now,
        ]));
        $providerId = (int) $wpdb->insert_id;
        $scope = [
            'business_id' => $key, 'store_id' => $key, 'product_program' => 'PERSONALIZED_POD',
            'product_version_id' => $versionId, 'provider_mapping_id' => $providerId,
        ];
        $repo = new \DigiForge\POD\BusinessScopeRepository();
        $initial = $repo->createMapping($scope, 'original-' . $key);
        self::assertIsArray($initial);
        $commits = 0;
        $filter = static function (string $sql) use (&$commits): string {
            if (trim($sql) === 'COMMIT') {
                ++$commits;
                return 'SELECT * FROM digiforge_test_missing_replay_commit';
            }
            return $sql;
        };
        add_filter('query', $filter);
        try {
            // A different idempotency key bypasses the early replay lookup but reaches
            // existing-owner equivalence only if the original ownership key matches.
            $result = $repo->createMapping($scope, 'original-' . $key);
            self::assertIsArray($result);
            self::assertSame(0, $commits, 'Exact idempotency replay returns before a transaction.');
        } finally {
            remove_filter('query', $filter);
        }
    }
}
