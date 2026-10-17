<?php
declare(strict_types=1);

use DigiForge\Finance\SourceCompletenessReadModel;
use PHPUnit\Framework\TestCase;

final class FinanceSourceCompletenessReadModelTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
        require_once dirname(__DIR__, 2) . '/includes/Database/Tables.php';
        require_once dirname(__DIR__, 2) . '/includes/Finance/Validator.php';
        require_once dirname(__DIR__, 2) . '/includes/Finance/SourceCompletenessReadModel.php';
    }

    /** @dataProvider evidenceCases */
    public function testIndependentSourceCoverage(array $orders, array $revenue, string $state, array $missing): void
    {
        global $wpdb;
        $original = $wpdb ?? null;
        try {
            $wpdb = new class($orders, $revenue) {
                public string $prefix = 'wp_';
                public string $last_error = '';
                public function __construct(private array $orders, private array $revenue) {}
                public function prepare(string $query, mixed ...$args): string { return $query; }
                public function get_results(string $query, mixed $mode): array
                {
                    return str_contains($query, 'finance_ledger') ? $this->revenue : $this->orders;
                }
            };
            $result = (new SourceCompletenessReadModel())->snapshot('test', '2026-09-01', '2026-09-30');
            self::assertSame($state, $result['state']);
            self::assertSame($missing, $result['missing_revenue_order_ids']);
            self::assertSame($state === 'INVALID_SOURCE_EVIDENCE' && $orders !== [] ? 1 : 0, $result['invalid_order_row_count']);
            self::assertSame($state === 'INVALID_SOURCE_EVIDENCE' && $orders === [] ? 1 : 0, $result['invalid_revenue_row_count']);
            self::assertSame($state === 'UNMATCHED_REVENUE_EVIDENCE' ? [8] : [], $result['orphan_revenue_order_ids']);
            self::assertSame($state === 'DUPLICATE_REVENUE_EVIDENCE' ? [7] : [], $result['duplicate_revenue_order_ids']);
            foreach (['cost_coverage_state', 'etsy_transaction_timing_state', 'etsy_fee_coverage_state', 'pod_actual_cost_coverage_state'] as $field) {
                self::assertSame('NOT_VERIFIED', $result[$field]);
            }
            self::assertFalse($result['approval_authorized']);
            self::assertFalse($result['external_execution_authorized']);
        } finally {
            $wpdb = $original;
        }
    }

    public static function evidenceCases(): array
    {
        return [
            'verified zero activity' => [[], [], 'ZERO_ACTIVITY', []],
            'order without revenue' => [[['id' => 7]], [], 'MISSING_REVENUE_EVIDENCE', [7]],
            'covered revenue with costs unverified' => [[['id' => 7]], [['source_id' => 7]], 'REVENUE_COVERED_COSTS_UNVERIFIED', []],
            'unmatched revenue record' => [[['id' => 7]], [['source_id' => 7], ['source_id' => 8]], 'UNMATCHED_REVENUE_EVIDENCE', []],
            'duplicate revenue record' => [[['id' => 7]], [['source_id' => 7], ['source_id' => 7]], 'DUPLICATE_REVENUE_EVIDENCE', []],
            'malformed order evidence' => [[['id' => 'invalid']], [], 'INVALID_SOURCE_EVIDENCE', []],
            'malformed revenue evidence' => [[], [['source_id' => 'invalid']], 'INVALID_SOURCE_EVIDENCE', []],
        ];
    }

    public function testReadFailureIsUnavailable(): void
    {
        global $wpdb;
        $original = $wpdb ?? null;
        try {
            $wpdb = new class {
                public string $prefix = 'wp_';
                public string $last_error = '';
                public function prepare(string $query, mixed ...$args): string { return $query; }
                public function get_results(string $query, mixed $mode): ?array
                {
                    if (str_contains($query, 'finance_ledger')) {
                        $this->last_error = 'database error';
                        return null;
                    }
                    return [];
                }
            };
            $result = (new SourceCompletenessReadModel())->snapshot('test', '2026-09-01', '2026-09-30');
            self::assertSame('UNAVAILABLE', $result['state']);
            self::assertNull($result['missing_revenue_order_ids']);
            self::assertNull($result['orphan_revenue_order_ids']);
            self::assertNull($result['duplicate_revenue_order_ids']);
            self::assertNull($result['invalid_order_row_count']);
            self::assertNull($result['invalid_revenue_row_count']);
            foreach (['cost_coverage_state', 'etsy_transaction_timing_state', 'etsy_fee_coverage_state', 'pod_actual_cost_coverage_state'] as $field) {
                self::assertSame('NOT_VERIFIED', $result[$field]);
            }
            self::assertFalse($result['approval_authorized']);
        } finally {
            $wpdb = $original;
        }
    }
}
