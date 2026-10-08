<?php
declare(strict_types=1);

final class PodIdempotencyReplayQueryFailureTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testReplayQueryFailureIsNotTreatedAsUnusedKey(): void
    {
        $table = \DigiForge\Database\Tables::pod_business_mappings();
        $filter = static function (string $sql) use ($table): string {
            if (str_contains($sql, 'SELECT * FROM ' . $table . ' WHERE idempotency_key=')) {
                return 'SELECT * FROM digiforge_test_nonexistent_idempotency_authority';
            }
            return $sql;
        };
        add_filter('query', $filter);
        try {
            $result = (new \DigiForge\POD\BusinessScopeRepository())->replay([], 'test-failed-evidence');
            self::assertWPError($result);
            self::assertSame('digiforge_idempotency_evidence_unavailable', $result->get_error_code());
        } finally {
            remove_filter('query', $filter);
        }
    }

    public function testMissingReplayKeyReturnsNull(): void
    {
        self::assertNull((new \DigiForge\POD\BusinessScopeRepository())->replay([], null));
    }
}
