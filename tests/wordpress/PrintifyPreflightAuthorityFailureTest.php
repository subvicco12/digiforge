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

    public function testApprovedPackageScanFailureCannotClaimZeroPreflightAttention(): void
    {
        global $wpdb;
        $table = \DigiForge\Database\Tables::pod_authorization_packages();
        $fault = static fn(string $sql): string => str_contains($sql, 'SELECT id FROM ' . $table . " WHERE state='APPROVED_PACKAGE'")
            ? 'SELECT id FROM digiforge_synthetic_absent_preflight_attention' : $sql;
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $fault);
        try {
            $result = (new \DigiForge\POD\ProductionPreflightAttentionReadModel())->summary();
            self::assertNull($result['approved_packages']);
            self::assertNull($result['preflight_current']);
            self::assertNull($result['revalidation_required']);
            self::assertNull($result['evaluation_errors']);
            self::assertSame('UNAVAILABLE', $result['query_state']);
            self::assertFalse($result['external_execution_performed']);
        } finally {
            remove_filter('query', $fault);
            $wpdb->suppress_errors($previous);
        }
    }

    public function testSuccessfulEmptyPackageScanKeepsObservedZeroCounts(): void
    {
        global $wpdb;
        $table = \DigiForge\Database\Tables::pod_authorization_packages();
        $empty = static fn(string $sql): string => str_contains($sql, 'SELECT id FROM ' . $table . " WHERE state='APPROVED_PACKAGE'")
            ? 'SELECT id FROM ' . $table . ' WHERE 1=0' : $sql;
        add_filter('query', $empty);
        try {
            $wpdb->last_error = 'Synthetic previous query failure';
            $result = (new \DigiForge\POD\ProductionPreflightAttentionReadModel())->summary();
            self::assertSame('AVAILABLE', $result['query_state']);
            foreach (['approved_packages', 'preflight_current', 'revalidation_required', 'evaluation_errors'] as $key) {
                self::assertSame(0, $result[$key]);
            }
            self::assertFalse($result['external_execution_performed']);
        } finally {
            remove_filter('query', $empty);
        }
    }
}
