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
        $closed = $repo->transition('period', (int) $row['id'], 'CLOSED');
        self::assertIsArray($closed);
        self::assertSame('CLOSED', $closed['state']);
        self::assertSame($approved['approved_by'], $closed['approved_by']);
        self::assertSame($approved['approved_at'], $closed['approved_at']);
        self::assertTrue($repo->transition('period', (int) $row['id'], 'CLOSED')['idempotent_transition']);
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

    public function testLateLedgerEvidencePreventsClosingAnEarlierApproval(): void
    {
        $row = $this->period();
        $repo = new Repository();
        self::assertIsArray($repo->transition('period', (int) $row['id'], 'APPROVED'));
        self::assertIsArray($repo->createLedger([
            'environment' => 'test', 'source_type' => 'manual', 'source_id' => 0,
            'entry_type' => 'REFUND_RESERVE', 'currency' => 'USD', 'amount' => 1,
            'effective_date' => '2026-09-10',
        ]));
        $result = $repo->transition('period', (int) $row['id'], 'CLOSED');
        self::assertWPError($result);
        self::assertSame('source_completeness_required', $result->get_error_code());
        global $wpdb;
        self::assertSame('APPROVED', $wpdb->get_var($wpdb->prepare(
            'SELECT state FROM ' . Tables::finance_periods() . ' WHERE id=%d', $row['id']
        )));
    }

    public function testClosingRequiresAnAuthenticExistingApproval(): void
    {
        $row = $this->period();
        global $wpdb;
        $wpdb->update(Tables::finance_periods(), ['state' => 'APPROVED', 'approved_by' => 0, 'approved_at' => null], ['id' => $row['id']]);
        $result = (new Repository())->transition('period', (int) $row['id'], 'CLOSED');
        self::assertWPError($result);
        self::assertSame('period_approval_required', $result->get_error_code());
    }

    public function testTamperedLedgerEvidenceCannotProduceAProfitabilityPeriod(): void
    {
        Activator::activate();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $repo = new Repository();
        $row = $repo->createLedger([
            'environment' => 'test', 'source_type' => 'manual', 'source_id' => 0,
            'entry_type' => 'REVENUE', 'currency' => 'USD', 'amount' => 10,
            'effective_date' => '2026-09-10', 'metadata' => ['fixture' => 'offline evidence'],
        ]);
        self::assertIsArray($row);
        global $wpdb;
        $wpdb->update(Tables::finance_ledger(), ['amount' => 999], ['id' => $row['id']]);
        $result = $repo->calculatePeriod([
            'environment' => 'test', 'period_start' => '2026-09-01',
            'period_end' => '2026-09-30', 'base_currency' => 'USD',
        ]);
        self::assertWPError($result);
        self::assertSame('ledger_integrity_invalid', $result->get_error_code());
        self::assertSame('0', $wpdb->get_var('SELECT COUNT(*) FROM ' . Tables::finance_periods()));
    }

    public function testSameCurrencyBaseAmountMustEqualTheOriginalAmount(): void
    {
        Activator::activate();
        $result = (new Repository())->createLedger([
            'environment' => 'test', 'entry_type' => 'REVENUE', 'currency' => 'USD',
            'amount' => 10, 'base_currency' => 'USD', 'base_amount' => 1,
            'effective_date' => '2026-09-10',
        ]);
        self::assertWPError($result);
        self::assertSame('fx_amount_conflict', $result->get_error_code());
    }

    public function testFxPersistencePreservesTheValidatedRateAndItsIdempotentReplay(): void
    {
        Activator::activate();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $input = [
            'environment' => 'test', 'base_currency' => 'USD', 'quote_currency' => 'EUR',
            'rate' => '123456789.1234567890', 'as_of_at' => '2026-09-10 12:00:00',
            'source_metadata' => ['fixture' => 'synthetic precision evidence'],
        ];
        $repo = new Repository();
        $row = $repo->createFx($input, 'finance-offline-precision');
        self::assertIsArray($row);
        self::assertSame(Validator::positiveRate($input['rate']), (float) $row['rate']);
        $replay = $repo->createFx($input, 'finance-offline-precision');
        self::assertIsArray($replay);
        self::assertTrue($replay['idempotent_replay']);
    }

    private function calculationInput(): array
    {
        return ['environment' => 'test', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'base_currency' => 'USD'];
    }

    private function lateLedger(float $amount = 0.0): array
    {
        $row = (new Repository())->createLedger([
            'environment' => 'test', 'entry_type' => 'COGS', 'currency' => 'USD',
            'amount' => $amount, 'effective_date' => '2026-09-10',
            'metadata' => ['fixture' => 'offline late evidence'],
        ]);
        self::assertIsArray($row);
        return $row;
    }

    public function testLateEvidenceCreatesAnImmutableRevisionAndUnchangedEvidenceReplays(): void
    {
        $original = $this->period();
        global $wpdb;
        $before = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Tables::finance_periods() . ' WHERE id=%d', $original['id']), ARRAY_A);
        $this->lateLedger(2.0);
        $repo = new Repository();
        $revision = $repo->calculatePeriod($this->calculationInput());
        self::assertIsArray($revision);
        self::assertNotSame($original['id'], $revision['id']);
        self::assertNotSame($original['calculation_version'], $revision['calculation_version']);
        self::assertMatchesRegularExpression('/^v2\.[a-z2-7]{52}$/D', $revision['calculation_version']);
        self::assertSame('CALCULATED', $revision['state']);
        self::assertSame('0', $revision['approved_by']);
        self::assertSame($before, $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Tables::finance_periods() . ' WHERE id=%d', $original['id']), ARRAY_A));
        $replay = $repo->calculatePeriod($this->calculationInput());
        self::assertIsArray($replay);
        self::assertSame($revision['id'], $replay['id']);
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame('2', $wpdb->get_var('SELECT COUNT(*) FROM ' . Tables::finance_periods()));
        self::assertIsArray($repo->transition('period', (int) $revision['id'], 'REVIEW_REQUIRED'));
        self::assertWPError($repo->transition('period', (int) $revision['id'], 'APPROVED'));
    }

    public function testEqualTotalsWithDifferentSourceRowsAndReconciliationProduceDifferentRevisions(): void
    {
        Activator::activate();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        global $wpdb;
        $first = $this->lateLedger();
        $wpdb->update(Tables::finance_ledger(), ['reconciliation_state' => 'RECONCILED'], ['id' => $first['id']]);
        $repo = new Repository();
        $one = $repo->calculatePeriod($this->calculationInput());
        self::assertIsArray($one);
        $second = $this->lateLedger();
        $wpdb->update(Tables::finance_ledger(), ['reconciliation_state' => 'RECONCILED'], ['id' => $second['id']]);
        $two = $repo->calculatePeriod($this->calculationInput());
        self::assertIsArray($two);
        self::assertSame($one['metrics'], $two['metrics']);
        self::assertNotSame($one['calculation_version'], $two['calculation_version']);
        $wpdb->update(Tables::finance_ledger(), ['reconciliation_state' => 'UNRECONCILED'], ['id' => $first['id']]);
        $three = $repo->calculatePeriod($this->calculationInput());
        self::assertIsArray($three);
        self::assertNotSame($two['calculation_version'], $three['calculation_version']);
        self::assertSame('3', $wpdb->get_var('SELECT COUNT(*) FROM ' . Tables::finance_periods()));
    }

    public function testRevisionRejectsOldIdempotencyKeyAndConflictingStoredEvidence(): void
    {
        Activator::activate();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $repo = new Repository();
        $one = $repo->calculatePeriod($this->calculationInput(), 'offline-period-original');
        self::assertIsArray($one);
        $this->lateLedger();
        $conflict = $repo->calculatePeriod($this->calculationInput(), 'offline-period-original');
        self::assertWPError($conflict);
        self::assertSame('idempotency_payload_conflict', $conflict->get_error_code());
        $two = $repo->calculatePeriod($this->calculationInput(), 'offline-period-revision');
        self::assertIsArray($two);
        global $wpdb;
        $wpdb->update(Tables::finance_periods(), ['metrics' => '{"gross_revenue":999}'], ['id' => $two['id']]);
        $tampered = $repo->calculatePeriod($this->calculationInput());
        self::assertWPError($tampered);
        self::assertSame('period_revision_conflict', $tampered->get_error_code());
        self::assertSame('2', $wpdb->get_var('SELECT COUNT(*) FROM ' . Tables::finance_periods()));
    }

    public function testRevisionLookupFailureNeverCreatesOrReplaysAPeriod(): void
    {
        $this->period();
        $filter = static fn(string $sql): string => str_starts_with($sql, 'SELECT * FROM ' . Tables::finance_periods() . ' WHERE environment=') ? 'SELECT * FROM missing_finance_revision_fixture' : $sql;
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $filter);
        try {
            $result = (new Repository())->calculatePeriod($this->calculationInput());
            self::assertWPError($result);
            self::assertSame('period_revision_evidence_unavailable', $result->get_error_code());
        } finally {
            remove_filter('query', $filter);
            $wpdb->suppress_errors($previous);
        }
        self::assertSame('1', $wpdb->get_var('SELECT COUNT(*) FROM ' . Tables::finance_periods()));
    }

    /** @dataProvider revisionRaceCases */
    public function testRevisionInsertRacesRequireMatchingAvailableEvidence(string $case): void
    {
        $original = $this->period();
        global $wpdb;
        if ($case === 'conflict') {
            $wpdb->update(Tables::finance_periods(), ['metrics' => '{"gross_revenue":999}'], ['id' => $original['id']]);
        }
        $reads = 0;
        $filter = static function(string $sql) use (&$reads, $case): string {
            if (!str_starts_with($sql, 'SELECT * FROM ' . Tables::finance_periods() . ' WHERE environment=')) return $sql;
            ++$reads;
            if ($reads === 1) return 'SELECT * FROM ' . Tables::finance_periods() . ' WHERE id=0';
            return $case === 'outage' ? 'SELECT * FROM missing_finance_revision_race_fixture' : $sql;
        };
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $filter);
        try {
            $result = (new Repository())->calculatePeriod($this->calculationInput());
            if ($case === 'matching') {
                self::assertIsArray($result);
                self::assertSame($original['id'], $result['id']);
                self::assertTrue($result['idempotent_replay']);
            } else {
                self::assertWPError($result);
                self::assertSame($case === 'outage' ? 'period_revision_evidence_unavailable' : 'period_revision_conflict', $result->get_error_code());
            }
        } finally {
            remove_filter('query', $filter);
            $wpdb->suppress_errors($previous);
        }
        self::assertSame(2, $reads);
        self::assertSame('1', $wpdb->get_var('SELECT COUNT(*) FROM ' . Tables::finance_periods()));
    }

    public static function revisionRaceCases(): array
    {
        return [['matching'], ['conflict'], ['outage']];
    }

    public function testRevisionInsertFailureCannotProduceASuccessfulEmptyRecord(): void
    {
        Activator::activate();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $filter = static fn(string $sql): string => str_starts_with($sql, 'INSERT INTO `' . Tables::finance_periods() . '`') ? str_replace(Tables::finance_periods(), 'missing_finance_revision_insert_fixture', $sql) : $sql;
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $filter);
        try {
            $result = (new Repository())->calculatePeriod($this->calculationInput());
            self::assertWPError($result);
            self::assertSame('database_error', $result->get_error_code());
        } finally {
            remove_filter('query', $filter);
            $wpdb->suppress_errors($previous);
        }
        self::assertSame('0', $wpdb->get_var('SELECT COUNT(*) FROM ' . Tables::finance_periods()));
    }
}
