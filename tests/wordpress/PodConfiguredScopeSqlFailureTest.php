<?php
declare(strict_types=1);

final class PodConfiguredScopeSqlFailureTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testAdminPolicyAndReadinessFailClosedOnRegistryOutage(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        $user = get_role('administrator');
        $hadCapability = $user->has_cap('manage_digiforge_pod');
        $user->add_cap('manage_digiforge_pod');
        $table = \DigiForge\Database\Tables::businesses();
        $filter = static function (string $sql) use ($table): string {
            if (str_contains($sql, 'SELECT * FROM ' . $table . ' WHERE business_key=')) {
                return 'SELECT * FROM digiforge_test_missing_scope_registry';
            }
            return $sql;
        };
        add_filter('query', $filter);
        $scope = ['business_id' => 'digiforge_missing_business', 'store_id' => 'digiforge_missing_store', 'product_program' => 'PERSONALIZED_POD'];
        try {
            $resolved = \DigiForge\POD\AdminScopePolicy::resolve($scope);
            self::assertWPError($resolved);
            self::assertSame('digiforge_pod_scope_evidence_unavailable', $resolved->get_error_code());
            self::assertSame(503, $resolved->get_error_data()['status']);
            self::assertFalse($resolved->get_error_data()['external_execution_authorized']);
            $readiness = \DigiForge\POD\ReadinessGate::assess($scope, [], [], []);
            self::assertWPError($readiness);
            self::assertSame('digiforge_pod_scope_evidence_unavailable', $readiness->get_error_code());
        } finally {
            remove_filter('query', $filter);
            if (!$hadCapability) {
                $user->remove_cap('manage_digiforge_pod');
            }
        }
    }

    public function testFailedBusinessRegistryQueryIsNotMisclassifiedAsMissingConfiguration(): void
    {
        $table = \DigiForge\Database\Tables::businesses();
        $filter = static function (string $sql) use ($table): string {
            if (str_contains($sql, 'SELECT * FROM ' . $table . ' WHERE business_key=')) {
                return 'SELECT * FROM digiforge_test_missing_scope_registry';
            }
            return $sql;
        };
        add_filter('query', $filter);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('POD business registry evidence unavailable');
            \DigiForge\POD\BusinessScope::resolveConfigured([
                'business_id' => 'digiforge_missing_business',
                'store_id' => 'digiforge_missing_store',
                'product_program' => 'PERSONALIZED_POD',
            ]);
        } finally {
            remove_filter('query', $filter);
        }
    }
}
