<?php
declare(strict_types=1);

namespace DigiForge\Finance;

use DigiForge\Database\Tables;

/**
 * Independent, read-only source coverage evidence.
 * Empty order and ledger populations are valid zero-activity observations.
 * This projection never grants approval or execution authority.
 */
final class SourceCompletenessReadModel
{
    public function snapshot(string $environment, string $start, string $end): array
    {
        $environment = Validator::environment($environment);
        $start = Validator::date($start);
        $end = Validator::date($end);
        if ($start > $end) {
            throw new \InvalidArgumentException('Period start must not be after period end.');
        }

        global $wpdb;
        $wpdb->last_error = '';
        $orders = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id FROM ' . Tables::orders() .
                ' WHERE environment=%s AND DATE(created_at) BETWEEN %s AND %s',
                $environment, $start, $end
            ),
            ARRAY_A
        );
        $ordersAvailable = is_array($orders) && empty($wpdb->last_error);

        $wpdb->last_error = '';
        $revenue = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT source_id FROM ' . Tables::finance_ledger() .
                ' WHERE environment=%s AND effective_date BETWEEN %s AND %s AND source_type=%s AND entry_type=%s',
                $environment, $start, $end, 'order', 'REVENUE'
            ),
            ARRAY_A
        );
        $ledgerAvailable = is_array($revenue) && empty($wpdb->last_error);

        if (!$ordersAvailable || !$ledgerAvailable) {
            return [
                'state' => 'UNAVAILABLE',
                'query_state' => ['orders' => $ordersAvailable ? 'AVAILABLE' : 'UNAVAILABLE', 'revenue' => $ledgerAvailable ? 'AVAILABLE' : 'UNAVAILABLE'],
                'expected_order_count' => null,
                'revenue_covered_order_count' => null,
                'missing_revenue_order_ids' => null,
                'cost_coverage_state' => 'NOT_VERIFIED',
                'etsy_transaction_timing_state' => 'NOT_VERIFIED',
                'etsy_fee_coverage_state' => 'NOT_VERIFIED',
                'pod_actual_cost_coverage_state' => 'NOT_VERIFIED',
                'approval_authorized' => false,
                'external_execution_authorized' => false,
            ];
        }

        $expected = [];
        foreach ($orders as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) $expected[$id] = true;
        }
        $covered = [];
        foreach ($revenue as $row) {
            $id = (int) ($row['source_id'] ?? 0);
            if ($id > 0 && isset($expected[$id])) $covered[$id] = true;
        }
        $missing = array_keys(array_diff_key($expected, $covered));
        sort($missing, SORT_NUMERIC);
        $zeroActivity = $expected === [] && $revenue === [];
        // Local order created_at is an intake timestamp, not authoritative Etsy paid_at.
        // Revenue ledger effective_date likewise does not prove Etsy fee settlement or POD actual cost.

        return [
            'state' => $zeroActivity ? 'ZERO_ACTIVITY' : ($missing === [] ? 'REVENUE_COVERED_COSTS_UNVERIFIED' : 'MISSING_REVENUE_EVIDENCE'),
            'query_state' => ['orders' => 'AVAILABLE', 'revenue' => 'AVAILABLE'],
            'expected_order_count' => count($expected),
            'revenue_covered_order_count' => count($covered),
            'missing_revenue_order_ids' => $missing,
            'cost_coverage_state' => 'NOT_VERIFIED',
            'etsy_transaction_timing_state' => 'NOT_VERIFIED',
            'etsy_fee_coverage_state' => 'NOT_VERIFIED',
            'pod_actual_cost_coverage_state' => 'NOT_VERIFIED',
            'approval_authorized' => false,
            'external_execution_authorized' => false,
        ];
    }
}
