<?php
declare(strict_types=1);

namespace DigiForge\Finance;

/**
 * Pure fail-closed evidence contract. Does not authorize approvals or execution.
 * Provenance is mandatory; local order intake and estimated POD snapshots are not proof.
 */
final class OrderCostEvidence
{
    public static function assess(array $evidence): array
    {
        $required = ['order_id', 'environment', 'etsy_transaction_id', 'etsy_paid_at',
            'etsy_fee_source_ref', 'etsy_fee_amount', 'pod_charge_source_ref',
            'pod_actual_cost_amount', 'currency', 'source_hash'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $evidence) || $evidence[$field] === null || $evidence[$field] === '') {
                return ['state' => 'MISSING_EVIDENCE', 'missing_field' => $field, 'verified' => false];
            }
        }
        if (!is_int($evidence['order_id']) || $evidence['order_id'] < 1
            || !in_array($evidence['environment'], ['test', 'live'], true)
            || !is_string($evidence['etsy_transaction_id'])
            || trim($evidence['etsy_transaction_id']) === ''
            || !is_string($evidence['etsy_fee_source_ref'])
            || trim($evidence['etsy_fee_source_ref']) === ''
            || !is_string($evidence['pod_charge_source_ref'])
            || trim($evidence['pod_charge_source_ref']) === ''
            || !is_string($evidence['currency'])
            || !preg_match('/^[A-Z]{3}$/D', $evidence['currency'])
            || !is_string($evidence['source_hash'])
            || !preg_match('/^[a-f0-9]{64}$/D', $evidence['source_hash'])) {
            return ['state' => 'INVALID_EVIDENCE', 'verified' => false];
        }
        if (!is_string($evidence['etsy_paid_at'])) {
            return ['state' => 'INVALID_TIMING', 'verified' => false];
        }
        $paidAt = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', (string) $evidence['etsy_paid_at']);
        if (!$paidAt || \DateTimeImmutable::getLastErrors() !== false || $paidAt->format('Y-m-d\TH:i:sP') !== $evidence['etsy_paid_at']) {
            return ['state' => 'INVALID_TIMING', 'verified' => false];
        }
        foreach (['etsy_fee_amount', 'pod_actual_cost_amount'] as $field) {
            if (!is_int($evidence[$field]) && !is_float($evidence[$field]) && !is_string($evidence[$field])) {
                return ['state' => 'INVALID_AMOUNT', 'verified' => false];
            }
            if (!is_numeric($evidence[$field]) || !is_finite((float) $evidence[$field])
                || (float) $evidence[$field] < 0 || (float) $evidence[$field] > 9999999999.9999) {
                return ['state' => 'INVALID_AMOUNT', 'verified' => false];
            }
        }
        // These are structural checks only. Independent source authentication, ledger
        // matching, finality, and immutable storage must precede any approval decision.
        return ['state' => 'STRUCTURALLY_COMPLETE_UNVERIFIED', 'verified' => false];
    }
}
