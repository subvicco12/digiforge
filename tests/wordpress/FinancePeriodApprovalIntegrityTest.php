<?php

declare(strict_types=1);

use DigiForge\Core\Activator;
use DigiForge\Database\Tables;
use DigiForge\Finance\Repository;
use DigiForge\Finance\Validator;

/** Synthetic local evidence only; no financial or production authority. */
final class FinancePeriodApprovalIntegrityTest extends WP_UnitTestCase
{
    /** @dataProvider sourceRaceCases */
    public function testLateSourceCannotEnterApprovedOrClosedPeriod(string $to, string $source): void
    {
        $row=$this->period();$repo=new Repository();global $wpdb;
        if($to==='CLOSED')$this->historicalApproval($row);
        $before=$to==='CLOSED'?'APPROVED':'REVIEW_REQUIRED';$changed=false;
        $filter=static function(string $sql)use(&$changed,$source):string{
            if(!$changed&&str_starts_with($sql,'UPDATE `'.Tables::finance_periods().'`')){
                $changed=true;global $wpdb;$now='2026-09-10 12:00:00';
                if($source==='order')self::assertSame(1,$wpdb->insert(Tables::orders(),['environment'=>'test','external_order_reference'=>'synthetic-period-race','created_at'=>$now,'updated_at'=>$now]));
                else self::assertIsArray((new Repository())->createLedger(['environment'=>'test','source_type'=>'manual','source_id'=>0,'entry_type'=>'COGS','currency'=>'USD','amount'=>1,'effective_date'=>'2026-09-10']));
            }
            return $sql;
        };
        add_filter('query',$filter);
        try{$result=$repo->transition('period',(int)$row['id'],$to);}finally{remove_filter('query',$filter);}
        self::assertFalse($changed,'Missing independent controls must deny before a state write.');self::assertWPError($result);
        self::assertSame($before,$wpdb->get_var($wpdb->prepare('SELECT state FROM '.Tables::finance_periods().' WHERE id=%d',$row['id'])));
    }
    public static function sourceRaceCases():array{return [['APPROVED','order'],['APPROVED','ledger'],['CLOSED','order'],['CLOSED','ledger']];}

    /** @dataProvider replayStates */
    public function testIdempotentReplayRechecksLateActivity(string $state):void
    {
        $row=$this->period();$repo=new Repository();$this->historicalApproval($row);
        if($state==='CLOSED')$this->historicalApproval($row,'CLOSED');
        $changed=false;$filter=static function(string $sql)use(&$changed):string{
            if(!$changed&&str_starts_with($sql,'SELECT id FROM '.Tables::finance_ledger().' WHERE environment=')){
                $changed=true;global $wpdb;self::assertSame(1,$wpdb->insert(Tables::orders(),['environment'=>'test','created_at'=>'2026-09-10 12:00:00','updated_at'=>'2026-09-10 12:00:00']));
            }return $sql;
        };
        add_filter('query',$filter);try{$result=$repo->transition('period',(int)$row['id'],$state);}finally{remove_filter('query',$filter);}
        self::assertTrue($changed);self::assertWPError($result);
    }
    public static function replayStates():array{return [['APPROVED'],['CLOSED']];}

    /** @dataProvider replayStates */
    public function testSeparatelyCommittedOrderCannotHideBehindTransactionSnapshot(string $to):void
    {
        $row=$this->period();$repo=new Repository();global $wpdb;
        if($to==='CLOSED')$this->historicalApproval($row);
        // Commit only this disposable fixture baseline, so serialization abort cannot erase it.
        $actor=get_current_user_id();self::assertNotFalse($wpdb->query('COMMIT'));self::assertNotFalse($wpdb->query('START TRANSACTION'));
        // Same disposable test database, separate connection; never production credentials.
        $other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->query('SET autocommit=1');$sourceId=0;$changed=false;
        $oldErrors=$wpdb->suppress_errors(true);
        $filter=static function(string $sql)use(&$changed,&$sourceId,$other):string{
            if(!$changed&&str_starts_with($sql,'UPDATE `'.Tables::finance_periods().'`')){
                $changed=true;self::assertSame(1,$other->insert(Tables::orders(),['environment'=>'test','created_at'=>'2026-09-10 12:00:00','updated_at'=>'2026-09-10 12:00:00']));$sourceId=(int)$other->insert_id;
            }return $sql;
        };
        add_filter('query',$filter);
        try{
            $result=$repo->transition('period',(int)$row['id'],$to);self::assertFalse($changed,'Missing independent controls must deny before a state write.');self::assertWPError($result);
            self::assertSame($to==='CLOSED'?'APPROVED':'REVIEW_REQUIRED',$wpdb->get_var($wpdb->prepare('SELECT state FROM '.Tables::finance_periods().' WHERE id=%d',$row['id'])));
        }finally{
            remove_filter('query',$filter);
            // Release fixture locks before cleaning the one separately committed synthetic row.
            $wpdb->query('ROLLBACK');if($sourceId>0)$other->delete(Tables::orders(),['id'=>$sourceId]);
            $other->delete(Tables::finance_periods(),['id'=>$row['id']]);$other->delete(Tables::audit_log(),['object_type'=>'finance_period','object_id'=>(string)$row['id']]);
            $other->delete($wpdb->usermeta,['user_id'=>$actor]);$other->delete($wpdb->users,['ID'=>$actor]);
            $other->close();$wpdb->suppress_errors($oldErrors);$wpdb->query('START TRANSACTION');
        }
    }

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

    public function testApprovedPolicyDeniesEmptyPeriodWithoutIndependentSourceControls(): void
    {
        $row=$this->period();global $wpdb;
        $before=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::finance_periods().' WHERE id=%d',$row['id']),ARRAY_A);
        $result=(new Repository())->transition('period',(int)$row['id'],'APPROVED');
        self::assertWPError($result);
        self::assertSame('source_controls_required',$result->get_error_code());
        self::assertSame($before,$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::finance_periods().' WHERE id=%d',$row['id']),ARRAY_A));
    }

    public function testEmptyLocalPeriodCannotBeApprovedOrClosedUnderP07(): void
    {
        $row=$this->period();$repo=new Repository();
        self::assertWPError($repo->transition('period',(int)$row['id'],'APPROVED'));
        self::assertWPError($repo->transition('period',(int)$row['id'],'CLOSED'));
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
    }

    /** Historical synthetic state only: no new approval authority is granted by this fixture. */
    private function historicalApproval(array $row,string $state='APPROVED'): void
    {
        global $wpdb;
        self::assertSame(1,$wpdb->update(Tables::finance_periods(),['state'=>$state,'approved_by'=>get_current_user_id(),'approved_at'=>'2026-09-30 23:59:59'],['id'=>$row['id']]));
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
        $this->historicalApproval($row);
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
        $this->historicalApproval($row);
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
        self::assertSame($input['rate'],$row['rate'],'Exact native FX decimal must not be replaced by a float-formatted approximation.');
        $replay = $repo->createFx($input, 'finance-offline-precision');
        self::assertIsArray($replay);
        self::assertTrue($replay['idempotent_replay']);
    }

    /** @dataProvider missingAmounts */
    public function testMissingAmountsNeverBecomeDeclaredZero(string $method,array $input): void
    {
        Activator::activate();
        self::assertWPError((new Repository())->$method($input));
    }
    public static function missingAmounts(): array
    {
        return [['createLedger',['environment'=>'test','entry_type'=>'REVENUE','currency'=>'USD','effective_date'=>'2026-09-10']],
            ['createTaxClassification',['environment'=>'test','currency'=>'USD']]];
    }

    public function testPeriodPortalNeverCertifiesZeroOrMissingMetrics(): void
    {
        $portal=new DigiForge\Portal\Portal();$method=new ReflectionMethod($portal,'periodMetric');$method->setAccessible(true);
        self::assertSame('0.00 (PROVISIONAL)',$method->invoke($portal,['metrics_valid'=>true,'metrics'=>['gross_revenue'=>0]],'gross_revenue',2));
        self::assertSame('UNKNOWN',$method->invoke($portal,['metrics_valid'=>true,'metrics'=>[]],'gross_revenue',2));
        self::assertSame('UNKNOWN',$method->invoke($portal,['metrics_valid'=>false,'metrics'=>['gross_revenue'=>0]],'gross_revenue',2));
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
