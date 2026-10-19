<?php

declare(strict_types=1);

use DigiForge\Core\Activator;
use DigiForge\Database\Tables;
use DigiForge\Finance\Repository;
use DigiForge\Finance\Validator;

/** Synthetic local evidence only; no financial or production authority. */
final class FinancePeriodApprovalIntegrityTest extends WP_UnitTestCase
{
    public function testMalformedUnresolvedCountsNeverAuthorizePeriodApproval(): void
    {
        Activator::activate();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        global $wpdb;
        foreach ([0.5, -0.5, '0.5', '-0.5', '0', false, null, 1] as $index => $count) {
            $start = sprintf('2026-09-%02d', $index + 1);
            $canonical = [
                'environment' => 'test', 'period_start' => $start,
                'period_end' => '2026-09-30', 'base_currency' => 'USD',
                'metrics' => ['unresolved_reconciliation_count' => $count],
                'calculation_version' => 'v2',
            ];
            $now = current_time('mysql', true);
            self::assertSame(1, $wpdb->insert(Tables::finance_periods(), [
                'environment' => 'test', 'period_start' => $start,
                'period_end' => '2026-09-30', 'base_currency' => 'USD',
                'metrics' => Validator::canonicalJson($canonical['metrics']),
                'metrics_hash' => Validator::hash($canonical), 'calculation_version' => 'v2',
                'state' => 'REVIEW_REQUIRED', 'created_by' => get_current_user_id(),
                'created_at' => $now, 'updated_at' => $now,
            ]));
            $id = (int) $wpdb->insert_id;
            $result = (new Repository())->transition('period', $id, 'APPROVED');
            self::assertWPError($result, 'Only canonical integer zero can satisfy reconciliation.');
            self::assertSame('reconciliation_required', $result->get_error_code());
            self::assertSame('REVIEW_REQUIRED', $wpdb->get_var($wpdb->prepare(
                'SELECT state FROM ' . Tables::finance_periods() . ' WHERE id=%d', $id
            )));
        }
    }

    private function period(): array
    {
        Activator::activate();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $row = (new Repository())->calculatePeriod([
            'environment' => 'test', 'period_start' => '2026-09-01',
            'period_end' => '2026-09-30', 'base_currency' => 'USD',
        ]);
        self::assertIsArray($row);
        global $wpdb;
        $wpdb->update(Tables::finance_periods(), ['state' => 'REVIEW_REQUIRED'], ['id' => $row['id']]);
        return $row;
    }

    public function testEmptyLocalPeriodCanBeReviewedWithoutAuthorizingExternalActions(): void
    {
        $row = $this->period();
        $repo = new Repository();
        $approved = $repo->transition('period', (int) $row['id'], 'APPROVED');
        self::assertIsArray($approved);
        self::assertSame('APPROVED', $approved['state']);
        self::assertSame($row['metrics_hash'], $approved['metrics_hash']);
        self::assertTrue($repo->transition('period', (int) $row['id'], 'APPROVED')['idempotent_transition']);
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
    }

    public function testMissingFeesOrActualChargesCannotBeReplacedByReconciledLocalEntries(): void
    {
        $row = $this->period();
        global $wpdb;
        foreach (['COGS', 'PLATFORM_FEE'] as $type) {
            $ledger = (new Repository())->createLedger([
                'environment' => 'test', 'source_type' => 'manual', 'source_id' => 0,
                'entry_type' => $type, 'currency' => 'USD', 'amount' => 1,
                'effective_date' => '2026-09-10', 'metadata' => ['fixture' => 'unverified offline only'],
            ]);
            self::assertIsArray($ledger);
            $wpdb->update(Tables::finance_ledger(), ['reconciliation_state' => 'RECONCILED'], ['id' => $ledger['id']]);
            $result = (new Repository())->transition('period', (int) $row['id'], 'APPROVED');
            self::assertWPError($result);
            self::assertSame('source_completeness_required', $result->get_error_code());
            self::assertSame('REVIEW_REQUIRED', $wpdb->get_var($wpdb->prepare(
                'SELECT state FROM ' . Tables::finance_periods() . ' WHERE id=%d', $row['id']
            )));
        }
    }

    public function testInvalidOrUnavailableSourceCoverageFailsClosed(): void
    {
        $row = $this->period();
        foreach (["SELECT 'bad' AS id", 'SELECT * FROM missing_finance_source_fixture'] as $replacement) {
            $filter = static fn(string $sql): string => str_starts_with($sql, 'SELECT id FROM ' . Tables::orders()) ? $replacement : $sql;
            global $wpdb;
            $previous = $wpdb->suppress_errors(true);
            add_filter('query', $filter);
            try {
                self::assertWPError((new Repository())->transition('period', (int) $row['id'], 'APPROVED'));
            } finally {
                remove_filter('query', $filter);
                $wpdb->suppress_errors($previous);
            }
        }
    }

    public function testCoveredRevenueIsNotAuthenticatedFeeOrActualChargeEvidence(): void
    {
        $row = $this->period();
        $filter = static function(string $sql): string {
            if (str_starts_with($sql, 'SELECT id FROM ' . Tables::orders())) return 'SELECT 7 AS id';
            if (str_starts_with($sql, 'SELECT source_id FROM ' . Tables::finance_ledger())) return 'SELECT 7 AS source_id';
            return $sql;
        };
        add_filter('query', $filter);
        try {
            $result = (new Repository())->transition('period', (int) $row['id'], 'APPROVED');
            self::assertWPError($result);
            self::assertSame('source_completeness_required', $result->get_error_code());
        } finally {
            remove_filter('query', $filter);
        }
    }

    public function testLedgerOutageAndLateActivityAlsoDenyIdempotentApproval(): void
    {
        $row = $this->period();
        $filter = static fn(string $sql): string => str_starts_with($sql, 'SELECT id FROM ' . Tables::finance_ledger()) ? 'SELECT * FROM missing_finance_ledger_fixture' : $sql;
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $filter);
        try {
            $result = (new Repository())->transition('period', (int) $row['id'], 'APPROVED');
            self::assertWPError($result);
            self::assertSame('source_evidence_unavailable', $result->get_error_code());
        } finally {
            remove_filter('query', $filter);
            $wpdb->suppress_errors($previous);
        }
        $repo = new Repository();
        self::assertIsArray($repo->transition('period', (int) $row['id'], 'APPROVED'));
        self::assertIsArray($repo->createLedger([
            'environment' => 'test', 'source_type' => 'manual', 'source_id' => 0,
            'entry_type' => 'COGS', 'currency' => 'USD', 'amount' => 1,
            'effective_date' => '2026-09-10',
        ]));
        self::assertWPError($repo->transition('period', (int) $row['id'], 'APPROVED'));
    }

    public function testStoredMetricsCannotContradictEmptySources(): void
    {
        $row = $this->period();
        $metrics = json_decode($row['metrics'], true);
        $metrics['gross_revenue'] = 10;
        $canonical = array_intersect_key($row, array_flip(['environment','period_start','period_end','base_currency','calculation_version']));
        $canonical['metrics'] = $metrics;
        global $wpdb;
        $wpdb->update(Tables::finance_periods(), [
            'metrics' => Validator::canonicalJson($metrics), 'metrics_hash' => Validator::hash($canonical),
        ], ['id' => $row['id']]);
        self::assertWPError((new Repository())->transition('period', (int) $row['id'], 'APPROVED'));
    }
}
