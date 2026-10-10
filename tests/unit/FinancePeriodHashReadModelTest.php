<?php
declare(strict_types=1);

use DigiForge\Finance\OperationsReadModel;
use DigiForge\Finance\Validator;
use PHPUnit\Framework\TestCase;

final class FinancePeriodHashReadModelTest extends TestCase
{
    public function testPeriodAndAnalyticsHashesFollowTheirDistinctWriterContracts(): void
    {
        if (! defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
        require_once dirname(__DIR__, 2) . '/includes/Database/Tables.php';
        require_once dirname(__DIR__, 2) . '/includes/Finance/Validator.php';
        require_once dirname(__DIR__, 2) . '/includes/Finance/OperationsReadModel.php';

        $metrics = ['gross_revenue' => 120.0, 'net_operating_profit' => 42.0];
        $canonical = [
            'environment' => 'test',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'base_currency' => 'USD',
            'metrics' => $metrics,
            'calculation_version' => 'v2',
        ];
        $period = $canonical + [
            'id' => 1,
            'metrics_hash' => Validator::hash($canonical),
        ];
        $period['metrics'] = Validator::canonicalJson($metrics);
        $analytics = [
            'id' => 2,
            'metrics' => Validator::canonicalJson($metrics),
            'metrics_hash' => hash('sha256', Validator::canonicalJson($metrics)),
        ];

        global $wpdb;
        $original = $wpdb ?? null;
        try {
            $wpdb = new class($period, $analytics) {
                public string $last_error = '';
                public string $prefix = 'wp_';
                public function __construct(private array $period, private array $analytics) {}
                public function prepare(string $sql, mixed ...$args): string { return $sql; }
                public function get_results(string $sql, mixed $mode): array
                {
                    return str_contains($sql, 'finance_periods') ? [$this->period] : [$this->analytics];
                }
            };
            $result = (new OperationsReadModel())->snapshot();
            self::assertTrue($result['periods'][0]['metrics_valid']);
            self::assertSame($metrics, $result['periods'][0]['metrics']);
            self::assertTrue($result['analytics'][0]['metrics_valid']);

            $wpdb = new class($period, $analytics) {
                public string $last_error = '';
                public function __construct(private array $period, private array $analytics) {}
                public function prepare(string $sql, mixed ...$args): string { return $sql; }
                public function get_results(string $sql, mixed $mode): array
                {
                    if (str_contains($sql, 'finance_periods')) {
                        $row = $this->period;
                        $row['base_currency'] = 'EUR';
                        return [$row];
                    }
                    $row = $this->analytics;
                    $row['metrics'] = '{"gross_revenue":999}';
                    return [$row];
                }
            };
            $tampered = (new OperationsReadModel())->snapshot();
            self::assertFalse($tampered['periods'][0]['metrics_valid']);
            self::assertSame([], $tampered['periods'][0]['metrics']);
            self::assertFalse($tampered['analytics'][0]['metrics_valid']);
            self::assertSame([], $tampered['analytics'][0]['metrics']);
        } finally {
            $wpdb = $original;
        }
    }
}
