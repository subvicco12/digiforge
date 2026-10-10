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
                'orphan_revenue_order_ids' => null,
                'duplicate_revenue_order_ids' => null,
                'invalid_order_row_count' => null,
                'invalid_revenue_row_count' => null,
                'cost_coverage_state' => 'NOT_VERIFIED',
                'etsy_transaction_timing_state' => 'NOT_VERIFIED',
                'etsy_fee_coverage_state' => 'NOT_VERIFIED',
                'pod_actual_cost_coverage_state' => 'NOT_VERIFIED',
                'approval_authorized' => false,
                'external_execution_authorized' => false,
            ];
        }

        $expected = [];
        $invalidOrderRows = 0;
        foreach ($orders as $row) {
            $rawId = is_array($row) ? ($row['id'] ?? null) : null;
            if (!is_scalar($rawId) || !ctype_digit((string) $rawId) || (int) $rawId < 1) {
                ++$invalidOrderRows;
                continue;
            }
            $expected[(int) $rawId] = true;
        }
        $covered = [];
        $revenueCounts = [];
        $invalidRevenueRows = 0;
        foreach ($revenue as $row) {
            $rawId = is_array($row) ? ($row['source_id'] ?? null) : null;
            if (!is_scalar($rawId) || !ctype_digit((string) $rawId) || (int) $rawId < 1) {
                ++$invalidRevenueRows;
                continue;
            }
            $id = (int) $rawId;
            $revenueCounts[$id] = ($revenueCounts[$id] ?? 0) + 1;
            if (isset($expected[$id])) $covered[$id] = true;
        }
        $missing = array_keys(array_diff_key($expected, $covered));
        sort($missing, SORT_NUMERIC);
        $orphanRevenue = [];
        foreach ($revenueCounts as $id => $count) {
            if (!isset($expected[$id])) $orphanRevenue[$id] = true;
        }
        $duplicateRevenueIds = [];
        foreach ($revenueCounts as $revenueId => $count) {
            if ($count > 1) $duplicateRevenueIds[] = (int) $revenueId;
        }
        sort($duplicateRevenueIds, SORT_NUMERIC);
        $orphanIds = array_keys($orphanRevenue);
        sort($orphanIds, SORT_NUMERIC);
        $zeroActivity = $expected === [] && $revenue === [];
        // Local order created_at is an intake timestamp, not authoritative Etsy paid_at.
        // Revenue ledger effective_date likewise does not prove Etsy fee settlement or POD actual cost.

        return [
            'state' => ($invalidOrderRows > 0 || $invalidRevenueRows > 0) ? 'INVALID_SOURCE_EVIDENCE' : ($zeroActivity ? 'ZERO_ACTIVITY' : ($orphanIds !== [] ? 'UNMATCHED_REVENUE_EVIDENCE' : ($duplicateRevenueIds !== [] ? 'DUPLICATE_REVENUE_EVIDENCE' : ($missing === [] ? 'REVENUE_COVERED_COSTS_UNVERIFIED' : 'MISSING_REVENUE_EVIDENCE')))),
            'query_state' => ['orders' => 'AVAILABLE', 'revenue' => 'AVAILABLE'],
            'expected_order_count' => count($expected),
            'revenue_covered_order_count' => count($covered),
            'missing_revenue_order_ids' => $missing,
            'orphan_revenue_order_ids' => $orphanIds,
            'duplicate_revenue_order_ids' => $duplicateRevenueIds,
            'invalid_order_row_count' => $invalidOrderRows,
            'invalid_revenue_row_count' => $invalidRevenueRows,
            'cost_coverage_state' => 'NOT_VERIFIED',
            'etsy_transaction_timing_state' => 'NOT_VERIFIED',
            'etsy_fee_coverage_state' => 'NOT_VERIFIED',
            'pod_actual_cost_coverage_state' => 'NOT_VERIFIED',
            'approval_authorized' => false,
            'external_execution_authorized' => false,
        ];
    }
}
