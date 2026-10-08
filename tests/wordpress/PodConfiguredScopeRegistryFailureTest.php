<?php
declare(strict_types=1);

final class PodConfiguredScopeRegistryFailureTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testMissingActiveBusinessDoesNotAuthorizeScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('active configured business is required');
        \DigiForge\POD\BusinessScope::resolveConfigured([
            'business_id' => 'digiforge_test_nonexistent_business',
            'store_id' => 'digiforge_test_nonexistent_store',
            'product_program' => 'PERSONALIZED_POD',
        ]);
    }

    public function testUnsupportedProgramIsRejectedBeforeRegistryLookup(): void
    {
        $businesses = \DigiForge\Database\Tables::businesses();
        $queries = 0;
        $filter = static function (string $sql) use ($businesses, &$queries): string {
            if (str_contains($sql, $businesses)) {
                ++$queries;
            }
            return $sql;
        };
        add_filter('query', $filter);
        try {
            try {
                \DigiForge\POD\BusinessScope::resolveConfigured([
                    'business_id' => 'digiforge_test_nonexistent_business',
                    'store_id' => 'digiforge_test_nonexistent_store',
                    'product_program' => 'UNSUPPORTED',
                ]);
                self::fail('Unsupported program must be rejected.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('unsupported product_program', $error->getMessage());
            }
            self::assertSame(0, $queries, 'Program validation must precede business registry reads.');
        } finally {
            remove_filter('query', $filter);
        }
    }
}
