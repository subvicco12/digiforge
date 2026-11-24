<?php
declare(strict_types=1);

if ($phase === 'reopen') {
    $history = $workspace->inspect()['proposals'];
    $receipts = array_column($history, 'transition_receipt');
    requireFixture(count($receipts) === 4, 'Lifecycle receipts were lost across restart');
    foreach ($receipts as $receipt) {
        requireFixture($receipt['before_sha256'] !== $receipt['after_sha256']
            && $receipt['scope'] === ['shop'=>'107','account'=>'fixture-account','integration'=>'fixture-read'],
            'Receipt lost exact scope or lifecycle evidence');
    }
    requireFixture($workspace->readRecords('orders','order-2',1,10)['data']['status'] === 'in-production'
        && $workspace->readRecords('orders','order-3',1,10)['data']['status'] === 'on-hold', 'Order effects were lost or guessed');
    requireFixture(count(array_filter($history, static fn(array $p): bool => $p['state'] === 'UNKNOWN')) === 1,
        'Uncertain production outcome was lost or replayed');
    echo "Lifecycle receipts and UNKNOWN quarantine survived reopen\n";
    return;
}
$decision = static function (array $proposal, string $action, ?string $fault = null) use ($workspace): array {
    $revision = $workspace->inspect()['revision'];
    return $workspace->execute($revision, hash('sha256', 'milestone:'.$revision), 'FIXTURE_PROPOSAL_REVIEW', [
        'proposal_id'=>$proposal['id'],'proposal_revision'=>$proposal['revision'],'decision'=>$action,
    ], readFault:$fault);
};
$approve = static function (string $op, array $input) use ($send,$operationResult,$decision): array {
    $result = $operationResult($send($op, $input, true));
    requireFixture(($result['proposal']['state'] ?? null) === 'PENDING_APPROVAL', 'Draft did not qualify: '.json_encode($result));
    $approved = $decision($result['proposal'], 'approve');
    requireFixture($approved['state'] === 'APPROVED_HELD' && !$approved['provider_execution_authorized'], 'Approval was not held');
    return $approved['proposal'];
};
$simulate = static function (array $proposal) use ($decision): void {
    $result = $decision($proposal, 'simulate_held');
    requireFixture($result['state'] === 'RECONCILED_FIXTURE_TRANSITION' && $result['fixture_effect_committed']
        && !$result['provider_execution_authorized'] && !$result['provider_outcome_confirmed'] && !$result['retry_permitted'],
        'Approved fixture transition did not reconcile: '.json_encode($result));
    requireFixture($decision($proposal, 'simulate_held')['reason'] === 'FIXTURE_TRANSITION_APPROVAL_MISMATCH',
        'Consumed approval was reusable');
};

$unpublish = $approve('PRODUCT_UNPUBLISH_NOTIFY', ['product_id'=>'product-2','expected_version'=>'3']);
$simulate($unpublish);
$publish = $approve('PRODUCT_PUBLISH', ['product_id'=>'product-2','expected_version'=>'4','publication_fields'=>['title','images']]);
$simulate($publish);
$lineOrder = ['external_id'=>'milestone-line']+$order;
$lineOrder['line_items'][] = ['product_id'=>'product-2','variant_id'=>9,'quantity'=>2];
$run('ORDER_CREATE', $lineOrder);
$line = $approve('LINE_ITEM_CANCEL', ['order_id'=>'order-3','line_item_id'=>'line-2']);
$simulate($line);
$cancel = $approve('ORDER_CANCEL', ['order_id'=>'order-2']);
$production = $approve('FIXTURE_PRODUCTION_CONFIRM', ['order_id'=>'order-2']);
$simulate($production);
requireFixture($decision($cancel, 'simulate_held')['reason'] === 'FIXTURE_TRANSITION_BASELINE_CHANGED',
    'A previously approved cancellation ignored production');
$unknown = $approve('FIXTURE_PRODUCTION_CONFIRM', ['order_id'=>'order-3']);
$result = $decision($unknown, 'simulate_held', 'lost_ack');
requireFixture($result['state'] === 'UNKNOWN' && !$result['fixture_effect_committed'] && !$result['retry_permitted'],
    'Lost acknowledgment did not preserve UNKNOWN quarantine');
requireFixture($decision($unknown, 'simulate_held')['reason'] === 'FIXTURE_TRANSITION_APPROVAL_MISMATCH',
    'Uncertain transition could be replayed');
echo "Lifecycle fixture PASS: publication and unpublication, line cancellation, production, stale cancellation, four replay denials and UNKNOWN\n";
