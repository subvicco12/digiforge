<?php
declare(strict_types=1);

final class PodActiveOwnershipEvidenceOutageTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testActiveOwnershipJoinOutageRejectsExecutionAuthority(): void
    {
        $reads = 0;
        $filter = static function (string $sql) use (&$reads): string {
            if (str_contains($sql, 'INNER JOIN') && str_contains($sql, 'm.provider_mapping_id=')
                && str_contains($sql, \DigiForge\Database\Tables::pod_business_mappings())) {
                ++$reads;
                return 'SELECT * FROM digiforge_test_missing_active_ownership_authority';
            }
            return $sql;
        };
        add_filter('query', $filter);
        try {
            $result = (new \DigiForge\POD\BusinessScopeRepository())->assertActiveOwnershipForMapping(2147483647);
            self::assertWPError($result);
            self::assertSame('digiforge_scope_evidence_unavailable', $result->get_error_code());
            self::assertSame(503, $result->get_error_data()['status']);
            self::assertSame(false, $result->get_error_data()['retry_permitted']);
            self::assertSame(false, $result->get_error_data()['external_execution_authorized']);
            self::assertSame(1, $reads);
        } finally {
            remove_filter('query', $filter);
        }
    }
}
