<?php
declare(strict_types=1);

final class PodConfiguredScopeSqlFailureTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
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
