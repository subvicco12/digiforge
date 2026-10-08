<?php
declare(strict_types=1);

final class PodIdempotencyWhitespaceKeyGuardTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testWhitespaceIdempotencyKeySkipsReplayQuery(): void
    {
        $queries = 0;
        $filter = static function (string $sql) use (&$queries): string {
            if (str_contains($sql, \DigiForge\Database\Tables::pod_business_mappings())
                && str_contains($sql, 'idempotency_key=')) {
                ++$queries;
            }
            return $sql;
        };
        add_filter('query', $filter);
        try {
            $result = (new \DigiForge\POD\BusinessScopeRepository())->replay([], '   ');
            self::assertNull($result);
            self::assertSame(0, $queries);
        } finally {
            remove_filter('query', $filter);
        }
    }
}
