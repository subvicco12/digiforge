<?php

declare(strict_types=1);

use DigiForge\Finance\OrderCostEvidence;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/Finance/OrderCostEvidence.php';

final class OrderCostEvidenceTest extends TestCase
{
    public function testMissingEvidenceCannotVerify(): void
    {
        $result = OrderCostEvidence::assess(['order_id' => 1]);
        self::assertSame('MISSING_EVIDENCE', $result['state']);
        self::assertFalse($result['verified']);
    }

    public function testWhitespaceProvenanceIsRejected(): void
    {
        $evidence = [
            'order_id' => 1,
            'environment' => 'test',
            'etsy_transaction_id' => ' ',
            'etsy_paid_at' => '2026-10-10T12:00:00+00:00',
            'etsy_fee_source_ref' => 'statement',
            'etsy_fee_amount' => '0.00',
            'pod_charge_source_ref' => 'invoice',
            'pod_actual_cost_amount' => '0.00',
            'currency' => 'USD',
            'source_hash' => str_repeat('a', 64),
        ];
        self::assertSame('INVALID_EVIDENCE', OrderCostEvidence::assess($evidence)['state']);
        $evidence['etsy_transaction_id'] = 'transaction';
        self::assertSame('STRUCTURALLY_COMPLETE_UNVERIFIED', OrderCostEvidence::assess($evidence)['state']);
        self::assertFalse(OrderCostEvidence::assess($evidence)['verified']);
    }

    public function testMalformedTimingAndAmountsFailClosed(): void
    {
        $evidence = [
            'order_id' => 7,
            'environment' => 'test',
            'etsy_transaction_id' => 'tx-7',
            'etsy_paid_at' => '2026-10-10T12:00:00+00:00',
            'etsy_fee_source_ref' => 'statement-7',
            'etsy_fee_amount' => '0.00',
            'pod_charge_source_ref' => 'invoice-7',
            'pod_actual_cost_amount' => '0.00',
            'currency' => 'USD',
            'source_hash' => str_repeat('a', 64),
        ];
        $evidence['etsy_paid_at'] = '2026-02-30T12:00:00+00:00';
        self::assertSame('INVALID_TIMING', OrderCostEvidence::assess($evidence)['state']);
        $evidence['etsy_paid_at'] = '2026-10-10T12:00:00+00:00';
        foreach (['NAN', 'INF', '-0.01', '1e999'] as $invalid) {
            $evidence['etsy_fee_amount'] = $invalid;
            self::assertSame('INVALID_AMOUNT', OrderCostEvidence::assess($evidence)['state']);
        }
        $evidence['etsy_fee_amount'] = '0.00';
        $evidence['pod_actual_cost_amount'] = '-1';
        self::assertSame('INVALID_AMOUNT', OrderCostEvidence::assess($evidence)['state']);
    }
}
