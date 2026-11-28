<?php

declare(strict_types=1);

use DigiForge\Queue\JobRepository;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/Queue/JobRepository.php';
require_once dirname(__DIR__, 2) . '/includes/Queue/QueueFailurePolicy.php';

/**
 * Repository behavior with a recording database boundary; no database is started.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class QueueRetryAdmissionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
        if (!function_exists('current_time')) {
            eval('function current_time($type, $utc = false) { return "2026-11-15 00:00:00"; }');
        }
        $GLOBALS['wpdb'] = new QueueRetryAdmissionDatabase();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    public function testGenericTransitionCannotBypassRetryClassification(): void
    {
        self::assertFalse((new JobRepository())->transition(7, 'RUNNING', 'RETRY'));
        self::assertSame([], $GLOBALS['wpdb']->prepared);
        self::assertSame([], $GLOBALS['wpdb']->queries);
    }

    public function testGenericTransitionStillCannotBypassLeaseAcquisition(): void
    {
        self::assertFalse((new JobRepository())->transition(7, 'QUEUED', 'RUNNING'));
        self::assertSame([], $GLOBALS['wpdb']->queries);
    }

    public function testOrdinaryTransitionStillPersistsWithExpectedPriorState(): void
    {
        self::assertTrue((new JobRepository())->transition(7, 'RUNNING', 'HUMAN_REVIEW'));
        $write = $GLOBALS['wpdb']->queries[0];
        self::assertStringContainsString('WHERE id = %d AND state = %s', $write['sql']);
        self::assertSame(['HUMAN_REVIEW', '2026-11-15 00:00:00', 7, 'RUNNING'], $write['args']);
    }

    public function testClassifiedRetryPersistsAttemptAndBackoffWithPriorStateGuard(): void
    {
        $before = time();
        self::assertTrue((new JobRepository())->scheduleClassifiedRetry(7, 60,
            'digiforge_launch_ai_rate_limited', ['retry_permitted' => true, 'outcome' => 'NOT_SENT']));
        $write = $GLOBALS['wpdb']->queries[0];
        self::assertStringContainsString("SET state = 'RETRY'", $write['sql']);
        self::assertStringContainsString("WHERE id = %d AND state = 'RUNNING' AND attempts = %d", $write['sql']);
        self::assertStringContainsString('locked_by = NULL, locked_at = NULL, lease_expires_at = NULL', $write['sql']);
        self::assertSame(1, $write['args'][0]);
        self::assertGreaterThanOrEqual($before + 60, strtotime($write['args'][1] . ' UTC'));
        self::assertLessThanOrEqual(time() + 60, strtotime($write['args'][1] . ' UTC'));
        self::assertSame([7, 0], array_slice($write['args'], -2));
    }

    public function testClassifiedRetryAtAttemptCeilingStillDeadLetters(): void
    {
        $GLOBALS['wpdb']->row = ['attempts' => 2, 'max_attempts' => 3];
        self::assertTrue((new JobRepository())->scheduleRetry(7, 60,
            'digiforge_launch_ai_rate_limited', ['retry_permitted' => true]));
        $write = $GLOBALS['wpdb']->queries[0];
        self::assertStringContainsString("SET state = 'DEAD_LETTER'", $write['sql']);
        self::assertSame(3, $write['args'][0]);
        self::assertSame([7, 2], array_slice($write['args'], -2));
    }

    public function testUnknownOutcomeCannotPersistRetryEvenWithExplicitPermission(): void
    {
        self::assertFalse((new JobRepository())->scheduleRetry(7, 60,
            'digiforge_launch_ai_rate_limited', ['retry_permitted' => true, 'outcome' => 'UNKNOWN']));
        self::assertSame([], $GLOBALS['wpdb']->queries);
    }
}

/** Records the SQL/parameters emitted by the real repository, without emulating SQL. */
final class QueueRetryAdmissionDatabase
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    public array $row = ['attempts' => 0, 'max_attempts' => 3];
    public array $prepared = [];
    public array $queries = [];

    public function prepare(string $sql, mixed ...$args): string
    {
        $this->prepared[] = ['sql' => $sql, 'args' => $args];
        return (string)(count($this->prepared) - 1);
    }

    public function get_row(string $query, mixed $mode): array
    {
        return $this->row;
    }

    public function query(string $query): int
    {
        $this->queries[] = $this->prepared[(int)$query];
        return 1;
    }
}
