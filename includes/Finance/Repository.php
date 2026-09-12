<?php

declare(strict_types=1);

namespace DigiForge\Finance;

use DigiForge\Database\Tables;
use DigiForge\Security\Logger;
use WP_Error;

final class Repository
{
    public function createLedger(array $input, ?string $key = null): array|WP_Error
    {
        try {
            $environment = Validator::environment((string)($input['environment'] ?? ''));
            $entryType = Validator::entryType((string)($input['entry_type'] ?? ''));
            $currency = Validator::currency((string)($input['currency'] ?? ''));
            $amount = Validator::amount($input['amount'] ?? 0);
            $effective = Validator::date((string)($input['effective_date'] ?? ''));
            $metadata = Validator::structured((array)($input['metadata'] ?? []));
        } catch (\InvalidArgumentException $e) {
            return $this->error('validation', $e->getMessage());
        }
        $sourceType = sanitize_key((string)($input['source_type'] ?? 'manual'));
        $sourceId = absint($input['source_id'] ?? 0);
        $relationship = $this->validateSource($sourceType, $sourceId, $environment);
        if (is_wp_error($relationship)) {
            return $relationship;
        }
        $baseCurrency = trim((string)($input['base_currency'] ?? ''));
        $baseAmount = null;
        if ($baseCurrency !== '') {
            try {
                $baseCurrency = Validator::currency($baseCurrency);
                $baseAmount = Validator::amount($input['base_amount'] ?? $amount);
            } catch (\InvalidArgumentException $e) {
                return $this->error('validation', $e->getMessage());
            }
        }
        $canonical = [
            'environment'=>$environment,'source_type'=>$sourceType,'source_id'=>$sourceId,
            'entry_type'=>$entryType,'currency'=>$currency,'amount'=>$amount,'base_currency'=>$baseCurrency,
            'base_amount'=>$baseAmount,'effective_date'=>$effective,'metadata'=>$metadata,
        ];
        return $this->insert(Tables::finance_ledger(), $key, [
            'environment'=>$environment,'source_type'=>$sourceType,'source_id'=>$sourceId,'entry_type'=>$entryType,
            'currency'=>$currency,'amount'=>$amount,'base_currency'=>$baseCurrency,'base_amount'=>$baseAmount,
            'effective_date'=>$effective,'metadata'=>Validator::canonicalJson($metadata),
            'canonical_hash'=>Validator::hash($canonical),'reconciliation_state'=>'UNRECONCILED',
            'created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now(),
        ], 'finance_ledger');
    }

    public function createFx(array $input, ?string $key = null): array|WP_Error
    {
        try {
            $environment = Validator::environment((string)($input['environment'] ?? ''));
            $base = Validator::currency((string)($input['base_currency'] ?? ''));
            $quote = Validator::currency((string)($input['quote_currency'] ?? ''));
            $rate = Validator::positiveRate($input['rate'] ?? 0);
            $metadata = Validator::structured((array)($input['source_metadata'] ?? []));
        } catch (\InvalidArgumentException $e) {
            return $this->error('validation', $e->getMessage());
        }
        if ($base === $quote) {
            return $this->error('validation', 'FX currency pair must contain different currencies.');
        }
        $asOf = sanitize_text_field((string)($input['as_of_at'] ?? $this->now()));
        $canonical = ['environment'=>$environment,'base'=>$base,'quote'=>$quote,'rate'=>$rate,'as_of_at'=>$asOf,'source'=>$metadata];
        return $this->insert(Tables::fx_snapshots(), $key, [
            'environment'=>$environment,'base_currency'=>$base,'quote_currency'=>$quote,'rate'=>$rate,
            'as_of_at'=>$asOf,'source_metadata'=>Validator::canonicalJson($metadata),
            'canonical_hash'=>Validator::hash($canonical),'created_by'=>get_current_user_id(),'created_at'=>$this->now(),
        ], 'fx_snapshot');
    }

    public function createTaxClassification(array $input, ?string $key = null): array|WP_Error
    {
        try {
            $environment = Validator::environment((string)($input['environment'] ?? ''));
            $currency = Validator::currency((string)($input['currency'] ?? ''));
            $amount = Validator::amount($input['amount_basis'] ?? 0);
            $evidence = Validator::structured((array)($input['evidence'] ?? []));
        } catch (\InvalidArgumentException $e) {
            return $this->error('validation', $e->getMessage());
        }
        $sourceType = sanitize_key((string)($input['source_type'] ?? 'manual'));
        $sourceId = absint($input['source_id'] ?? 0);
        $relationship = $this->validateSource($sourceType, $sourceId, $environment);
        if (is_wp_error($relationship)) {
            return $relationship;
        }
        $classification = strtoupper(sanitize_key((string)($input['classification'] ?? 'REVIEW_REQUIRED')));
        if (! in_array($classification, ['TAXABLE','NON_TAXABLE','REVIEW_REQUIRED'], true)) {
            return $this->error('validation', 'Invalid tax classification.');
        }
        return $this->insert(Tables::tax_classifications(), $key, [
            'environment'=>$environment,'source_type'=>$sourceType,'source_id'=>$sourceId,
            'jurisdiction'=>sanitize_text_field((string)($input['jurisdiction'] ?? '')),
            'tax_category'=>sanitize_key((string)($input['tax_category'] ?? '')),'classification'=>$classification,
            'currency'=>$currency,'amount_basis'=>$amount,'evidence'=>Validator::canonicalJson($evidence),
            'review_status'=>'UNREVIEWED','reviewed_by'=>0,'reviewed_at'=>null,
            'created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now(),
        ], 'tax_classification');
    }

    public function calculatePeriod(array $input, ?string $key = null): array|WP_Error
    {
        try {
            $environment = Validator::environment((string)($input['environment'] ?? ''));
            $start = Validator::date((string)($input['period_start'] ?? ''));
            $end = Validator::date((string)($input['period_end'] ?? ''));
            $baseCurrency = Validator::currency((string)($input['base_currency'] ?? 'USD'));
        } catch (\InvalidArgumentException $e) {
            return $this->error('validation', $e->getMessage());
        }
        if ($start > $end) {
            return $this->error('validation', 'Period start must not be after period end.');
        }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT entry_type, amount, base_amount, currency, reconciliation_state FROM '.Tables::finance_ledger().' WHERE environment=%s AND effective_date BETWEEN %s AND %s',
            $environment, $start, $end
        ), ARRAY_A) ?: [];
        $metrics = [
            'gross_revenue'=>0.0,'refund_reserves'=>0.0,'platform_payment_fees'=>0.0,'shipping_cost'=>0.0,
            'provider_cost'=>0.0,'advertising_cost'=>0.0,'tax_collected'=>0.0,'tax_expense'=>0.0,
            'other_income'=>0.0,'other_expense'=>0.0,'gross_profit'=>0.0,'contribution_profit'=>0.0,
            'net_operating_profit'=>0.0,'margin_percent'=>0.0,'unresolved_reconciliation_count'=>0,
        ];
        foreach ($rows as $row) {
            $value = $row['base_amount'] !== null ? (float)$row['base_amount'] : (float)$row['amount'];
            switch ((string)$row['entry_type']) {
                case 'REVENUE': $metrics['gross_revenue'] += $value; break;
                case 'REFUND_RESERVE': $metrics['refund_reserves'] += abs($value); break;
                case 'PLATFORM_FEE': case 'PAYMENT_FEE': $metrics['platform_payment_fees'] += abs($value); break;
                case 'SHIPPING_COST': $metrics['shipping_cost'] += abs($value); break;
                case 'COGS': $metrics['provider_cost'] += abs($value); break;
                case 'AD_SPEND': $metrics['advertising_cost'] += abs($value); break;
                case 'TAX_COLLECTED': $metrics['tax_collected'] += $value; break;
                case 'TAX_EXPENSE': $metrics['tax_expense'] += abs($value); break;
                case 'OTHER_INCOME': $metrics['other_income'] += $value; break;
                case 'OTHER_EXPENSE': $metrics['other_expense'] += abs($value); break;
                case 'ADJUSTMENT': $metrics['other_income'] += $value; break;
            }
            if ((string)$row['reconciliation_state'] !== 'RECONCILED') {
                $metrics['unresolved_reconciliation_count']++;
            }
        }
        $metrics['gross_profit'] = round($metrics['gross_revenue'] - $metrics['refund_reserves'] - $metrics['provider_cost'], 4);
        $metrics['contribution_profit'] = round($metrics['gross_profit'] - $metrics['platform_payment_fees'] - $metrics['shipping_cost'] - $metrics['advertising_cost'], 4);
        $metrics['net_operating_profit'] = round($metrics['contribution_profit'] + $metrics['other_income'] - $metrics['other_expense'] - $metrics['tax_expense'], 4);
        $metrics['margin_percent'] = $metrics['gross_revenue'] == 0.0 ? 0.0 : round(($metrics['net_operating_profit'] / $metrics['gross_revenue']) * 100, 4);
        $canonical = ['environment'=>$environment,'period_start'=>$start,'period_end'=>$end,'base_currency'=>$baseCurrency,'metrics'=>$metrics,'calculation_version'=>'v1'];
        return $this->insert(Tables::finance_periods(), $key, [
            'environment'=>$environment,'period_start'=>$start,'period_end'=>$end,'base_currency'=>$baseCurrency,
            'metrics'=>Validator::canonicalJson($metrics),'metrics_hash'=>Validator::hash($canonical),
            'calculation_version'=>'v1','state'=>'CALCULATED','approved_by'=>0,'approved_at'=>null,
            'created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now(),
        ], 'finance_period');
    }

    public function createIntent(array $input, ?string $key = null): array|WP_Error
    {
        try {
            $environment = Validator::environment((string)($input['environment'] ?? ''));
            $payload = Validator::structured((array)($input['input_payload'] ?? []));
        } catch (\InvalidArgumentException $e) {
            return $this->error('validation', $e->getMessage());
        }
        $type = strtoupper((string)($input['intent_type'] ?? ''));
        if (! in_array($type, Lifecycle::INTENT_TYPES, true)) {
            return $this->error('validation', 'Invalid finance intent type.');
        }
        return $this->insert(Tables::finance_intents(), $key, [
            'environment'=>$environment,'source_type'=>sanitize_key((string)($input['source_type'] ?? '')),
            'source_id'=>absint($input['source_id'] ?? 0),'intent_type'=>$type,
            'input_payload'=>Validator::canonicalJson($payload),'state'=>'BLOCKED','created_by'=>get_current_user_id(),
            'created_at'=>$this->now(),'updated_at'=>$this->now(),
        ], 'finance_intent');
    }

    public function transition(string $entity, int $id, string $to): array|WP_Error
    {
        $table = match ($entity) {
            'period' => Tables::finance_periods(), 'alert' => Tables::operational_alerts(),
            'intent' => Tables::finance_intents(), default => '',
        };
        if ($table === '') return $this->error('validation', 'Unknown finance lifecycle entity.');
        $row = $this->find($table, $id);
        if (! is_array($row) || ! Lifecycle::can($entity, (string)$row['state'], $to)) {
            return $this->error('invalid_transition', 'Finance lifecycle transition is not permitted.', 409);
        }
        if (in_array($to, ['APPROVED','APPROVED_INTENT'], true) && get_current_user_id() < 1) {
            return $this->error('reviewer_required', 'Authenticated human reviewer required.', 403);
        }
        global $wpdb;
        $data = ['state'=>$to,'updated_at'=>$this->now()];
        if ($entity === 'period' && $to === 'APPROVED') {
            $data['approved_by']=get_current_user_id(); $data['approved_at']=$this->now();
        }
        if ($wpdb->update($table, $data, ['id'=>$id,'state'=>(string)$row['state']]) !== 1) {
            return $this->error('transition_conflict', 'State changed concurrently or update failed.', 409);
        }
        Logger::audit('finance_state_changed', get_current_user_id(), $entity, (string)$id, ['from'=>$row['state'],'to'=>$to]);
        return $this->find($table, $id) ?: [];
    }

    public function list(string $entity, int $page = 1, int $perPage = 20): array
    {
        $tables = ['ledger'=>Tables::finance_ledger(),'fx'=>Tables::fx_snapshots(),'tax'=>Tables::tax_classifications(),
            'periods'=>Tables::finance_periods(),'analytics'=>Tables::analytics_snapshots(),'alerts'=>Tables::operational_alerts(),
            'intents'=>Tables::finance_intents()];
        $table = $tables[$entity] ?? '';
        if ($table === '') return ['items'=>[],'pagination'=>['total_items'=>0,'total_pages'=>0]];
        global $wpdb; $page=max(1,$page); $perPage=min(100,max(1,$perPage)); $offset=($page-1)*$perPage;
        $items=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d",$perPage,$offset),ARRAY_A) ?: [];
        $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
        return ['items'=>$items,'pagination'=>['total_items'=>$total,'total_pages'=>(int)ceil($total/max(1,$perPage))]];
    }

    private function validateSource(string $type, int $id, string $environment): true|WP_Error
    {
        if ($type === 'manual' && $id === 0) return true;
        $table = match ($type) {
            'order'=>Tables::orders(),'listing'=>Tables::listings(),'pod_cost_snapshot'=>Tables::pod_cost_snapshots(), default=>'',
        };
        if ($table === '' || $id < 1) return $this->error('invalid_source', 'Unsupported or missing finance source.');
        $row=$this->find($table,$id);
        if (! is_array($row)) return $this->error('invalid_source', 'Finance source was not found.', 409);
        if (isset($row['environment']) && (string)$row['environment'] !== $environment) {
            return $this->error('environment_mismatch', 'Finance source environment must match.', 409);
        }
        return true;
    }

    private function insert(string $table, ?string $key, array $data, string $objectType): array|WP_Error
    {
        global $wpdb;
        if ($key !== null && $key !== '') {
            $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE idempotency_key=%s LIMIT 1",$key),ARRAY_A);
            if (is_array($existing)) return $existing;
            $data['idempotency_key']=$key;
        }
        if ($wpdb->insert($table,$data) !== 1) return $this->error('database_error','Unable to persist finance record.',500);
        $id=(int)$wpdb->insert_id;
        Logger::audit($objectType.'_created',get_current_user_id(),$objectType,(string)$id,[]);
        return $this->find($table,$id) ?: [];
    }

    private function find(string $table, int $id): ?array
    {
        if ($id < 1) return null;
        global $wpdb; $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d LIMIT 1",$id),ARRAY_A);
        return is_array($row)?$row:null;
    }

    private function now(): string { return current_time('mysql', true); }
    private function error(string $code,string $message,int $status=400): WP_Error { return new WP_Error($code,$message,['status'=>$status]); }
}
