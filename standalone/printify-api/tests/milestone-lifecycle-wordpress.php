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
    $product = $workspace->readRecords('products','product-1',1,10)['data'];
    requireFixture($product['synthetic_version'] === 2 && $product['variants'][0]['price'] === 1550,
        'Reconfigured product did not survive reopening');
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
$alternate = "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',1,1,8,2,0,0,0))
    .$chunk('IDAT',gzcompress("\0\0\0\xff")).$chunk('IEND','');
$image = $operationResult($send('IMAGE_UPLOAD', ['file_name'=>'replacement.png','contents'=>base64_encode($alternate)]));
requireFixture(($image['data']['id'] ?? null) === 'image-2', 'Replacement artwork did not upload');
$configuration = ['product_id'=>'product-1','expected_version'=>'1','title'=>'Reconfigured local product',
    'description'=>'Replacement fixture art','variant_id'=>'9','price'=>'1450',
    'variants'=>[['variant_id'=>'10','price'=>'0','variant_enabled'=>'disabled']],
    'placements'=>[
        ['variant_id'=>'9','image_id'=>'image-2','position'=>'front','scale'=>'1.1'],
        ['variant_id'=>'10','image_id'=>'image-1','position'=>'back'],
    ]];
$old = $approve('PRODUCT_UPDATE', $configuration);
$amended = ['price'=>'1550']+$configuration;
$revised = $operationResult($send('PRODUCT_UPDATE',$amended,true,[],$old))['proposal'];
requireFixture($revised['state'] === 'PENDING_APPROVAL' && $revised['revision'] === $old['revision']+1
    && $revised['intent_sha256'] !== $old['intent_sha256']
    && $decision($old,'approve')['reason'] === 'PROPOSAL_SCOPE_OR_STALE_REVISION', 'Product edit did not invalidate approval');
$updated = $operationResult($send('PRODUCT_UPDATE', $amended));
requireFixture($updated['state'] === 'SYNTHETIC_OBSERVED' && $updated['data']['synthetic_version'] === 2
    && !$updated['provider_execution_authorized'], 'Structured replacement could not be applied');
$read = $workspace->readRecords('products','product-1',1,10)['data'];
requireFixture($read['variants'] === [['id'=>9,'price'=>1550,'is_enabled'=>true],['id'=>10,'price'=>0,'is_enabled'=>false]]
    && $read['print_areas'][0]['placeholders'][0]['images'][0]['id'] === 'image-2'
    && $read['print_areas'][1]['placeholders'][0]['position'] === 'back',
    'Structured product update lost price, variant availability, or exact artwork mapping');
echo "Structured product milestone PASS: replacement PNG, pricing, disabled variant, per-variant areas and stale approval denial\n";
