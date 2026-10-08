<?php
declare(strict_types=1);

final class PrintifyPreflightAuthorityFailureTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testPackageAuthoritySqlFailureBlocksExternalExecution(): void
    {
        $table = \DigiForge\Database\Tables::pod_authorization_packages();
        $filter = static function (string $sql) use ($table): string {
            if (str_contains($sql, 'SELECT * FROM ' . $table . ' WHERE id=')) {
                return 'SELECT * FROM digiforge_test_nonexistent_preflight_authority';
            }
            return $sql;
        };
        add_filter('query', $filter);
        try {
            $result = (new \DigiForge\POD\PrintifyProductionPreflight())->evaluate(2147483647);
            self::assertWPError($result);
            self::assertSame('printify_preflight_evidence_unavailable', $result->get_error_code());
            self::assertFalse($result->get_error_data()['external_execution_authorized']);
        } finally {
            remove_filter('query', $filter);
        }
    }

    public function testMissingPackageDoesNotGrantAuthorization(): void
    {
        $result = (new \DigiForge\POD\PrintifyProductionPreflight())->evaluate(2147483647);
        self::assertWPError($result);
        self::assertSame('printify_preflight_package_missing', $result->get_error_code());
    }
}
