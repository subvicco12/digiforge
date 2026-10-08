<?php
declare(strict_types=1);

final class PodOwnershipInvalidMappingIdGuardTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testInvalidMappingIdFailsBeforeAnyOwnershipQuery(): void
    {
        $queries = 0;
        $filter = static function (string $sql) use (&$queries): string {
            if (str_contains($sql, \DigiForge\Database\Tables::pod_business_mappings())) {
                ++$queries;
            }
            return $sql;
        };
        add_filter('query', $filter);
        try {
            $result = (new \DigiForge\POD\BusinessScopeRepository())->assertActiveOwnershipForMapping(0);
            self::assertWPError($result);
            self::assertSame('digiforge_scope_validation', $result->get_error_code());
            self::assertSame(0, $queries);
        } finally {
            remove_filter('query', $filter);
        }
    }
}
