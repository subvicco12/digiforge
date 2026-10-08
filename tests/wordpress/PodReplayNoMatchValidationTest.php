<?php
declare(strict_types=1);

final class PodReplayNoMatchValidationTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testUnknownIdempotencyKeyReturnsNullWithoutStartingTransaction(): void
    {
        $starts = 0;
        $filter = static function (string $sql) use (&$starts): string {
            if (trim($sql) === 'START TRANSACTION') {
                ++$starts;
            }
            return $sql;
        };
        add_filter('query', $filter);
        try {
            $result = (new \DigiForge\POD\BusinessScopeRepository())->replay(
                [], 'unknown-replay-' . wp_rand(100000, 999999)
            );
            self::assertNull($result);
            self::assertSame(0, $starts);
        } finally {
            remove_filter('query', $filter);
        }
    }
}
