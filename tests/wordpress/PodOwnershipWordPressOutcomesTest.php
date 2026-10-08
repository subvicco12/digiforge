<?php
declare(strict_types=1);

final class PodOwnershipWordPressOutcomesTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testMissingApprovedOwnershipFailsClosed(): void
    {
        $result = (new \DigiForge\POD\BusinessScopeRepository())->assertActiveOwnershipForMapping(2147483647);
        self::assertWPError($result);
        self::assertSame('digiforge_scope_inactive', $result->get_error_code());
    }

    public function testInvalidMappingIdIsRejectedBeforeQuery(): void
    {
        $result = (new \DigiForge\POD\BusinessScopeRepository())->assertActiveOwnershipForMapping(0);
        self::assertWPError($result);
        self::assertSame('digiforge_scope_validation', $result->get_error_code());
    }

    public function testQueryFailureIsNotMisclassifiedAsMissingOwnership(): void
    {
        $table = \DigiForge\Database\Tables::pod_business_mappings();
        $filter = static function (string $sql) use ($table): string {
            if (str_contains($sql, 'SELECT m.*,b.business_key,s.store_key,p.program_key FROM ' . $table . ' m ')) {
                return 'SELECT * FROM digiforge_test_intentionally_missing_authority_table';
            }
            return $sql;
        };
        add_filter('query', $filter);
        try {
            $result = (new \DigiForge\POD\BusinessScopeRepository())->assertActiveOwnershipForMapping(2147483647);
            self::assertWPError($result);
            self::assertSame('digiforge_scope_evidence_unavailable', $result->get_error_code());
        } finally {
            remove_filter('query', $filter);
        }
    }
}
