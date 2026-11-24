<?php
declare(strict_types=1);

/** Included by the disposable workspace harness on both write and reopen. */
if ($phase === 'reopen') {
    $first = $workspace->readRecords('products', 'product-1', 1, 10)['data'];
    $third = $workspace->readRecords('products', 'product-3', 1, 10)['data'];
    requireFixture(($first['publication_state'] ?? null) === 'PUBLISHED'
        && ($first['synthetic_version'] ?? null) === 4, 'Published revision did not survive process restart');
    requireFixture(($third['publication_state'] ?? null) === 'FAILED', 'Failed publication did not persist');
    $entries = array_column($workspace->inspect()['audit'], 'operation');
    foreach (['ORDER_ADDRESS_CHANGE', 'SUPPORT_ADDRESS_CHANGE', 'SUPPORT_LIST', 'SUPPORT_DETAIL'] as $operation) {
        requireFixture(in_array($operation, $entries, true), 'Missing durable operator receipt: '.$operation);
    }
    $drafts = array_values(array_filter($workspace->inspect()['proposals'], static fn(array $p): bool =>
        $p['operation'] === 'ORDER_CREATE'));
    requireFixture(count($drafts) === 1 && $drafts[0]['revision'] === 3 && $drafts[0]['state'] === 'PENDING_APPROVAL'
        && $drafts[0]['configuration']['line_items'][1] === ['product_id'=>'product-3','variant_id'=>10,'quantity'=>3],
        'Order revision or line edit did not survive restart');
    $shipments = $workspace->readRecords('orders', 'order-3', 1, 10)['data']['shipments'];
    requireFixture(array_column($shipments, 'tracking_reference') === [
        'SYNTHETIC-order-3-shipment-1','SYNTHETIC-order-3-shipment-2',
    ], 'Multiple fixture tracking references did not survive restart');
    $history = array_column($workspace->inspect()['events'], 'shipment_receipt');
    $export = $workspace->redactedAuditExport();
    requireFixture(count($history) === 3 && $history[0]['outcome'] === 'DELIVERY_OBSERVED'
        && $history[0]['order_status'] === 'partially-fulfilled'
        && $history[2]['order_status'] === 'fulfilled'
        && $shipments[0]['delivered_at'] === '2026-11-19T10:05:00Z'
        && in_array($history[0]['event_digest'], array_column($export['entries'], 'event_digest'), true),
        'Shipment chronology, delivery state or scoped receipt export did not survive reopen');
    echo "Structured WordPress forms: two publication outcomes, revision and four operation receipts survived reopen\n";
    return;
}
$checks = 0;
$post = static function (string $op, array $fields, ?string $reason = null, bool $preview = false) use ($send, $operationResult, &$checks): array {
    $result = $operationResult($send($op, $fields, $preview));
    requireFixture($reason === null ? $result['state'] === 'SYNTHETIC_OBSERVED'
        : (in_array($result['state'], ['DENIED','DEFINITIVE_ERROR'], true) && ($result['reason'] ?? '') === $reason),
        $op.' unexpected result '.json_encode($result, JSON_THROW_ON_ERROR));
    foreach (['provider_execution_authorized', 'provider_outcome_confirmed', 'retry_permitted'] as $flag) {
        requireFixture(($result[$flag] ?? false) === false, $op.' admitted '.$flag);
    }
    ++$checks;
    return $result;
};
$configuration = ['product_id'=>'product-1','expected_version'=>'1','variant_id'=>'9','price'=>'1234',
    'variants'=>[['variant_id'=>'10','price'=>'0','variant_enabled'=>'disabled']],
    'placements'=>[['variant_id'=>'9','image_id'=>'image-1','position'=>'front'],
        ['variant_id'=>'10','image_id'=>'image-1','position'=>'front']]];
$post('PRODUCT_UPDATE', $configuration);
$post('PRODUCT_UPDATE', $configuration, 'CONFLICT');
$updated = $workspace->readRecords('products', 'product-1', 1, 10)['data'];
requireFixture(($updated['variants'][1]['is_enabled'] ?? null) === false, 'Disabled variant was discarded');
$publish = ['product_id'=>'product-1','expected_version'=>'2','publication_fields'=>['title','images','keyFeatures']];
$post('PRODUCT_PUBLISH', $publish, null, true);
$post('PRODUCT_PUBLISH', ['publication_fields'=>['title','title']]+$publish, 'INVALID_OPERATION_FIELD', true);
$run('PRODUCT_PUBLISH', ['product_id'=>'product-1','expected_version'=>2,'publish_fields'=>['title'=>true,'images'=>true]]);
$post('PRODUCT_PUBLISH_SUCCEEDED', ['product_id'=>'product-1','expected_version'=>'3','publication_reference'=>'published-fixture']);
$post('PRODUCT_PUBLISH_SUCCEEDED', ['product_id'=>'product-1','expected_version'=>'3','publication_reference'=>'published-fixture'], 'CONFLICT');
$post('PRODUCT_UNPUBLISH_NOTIFY', ['product_id'=>'product-1','expected_version'=>'4'], null, true);
$run('PRODUCT_CREATE', ['title'=>'Reconciled failure fixture']+$product);
$run('PRODUCT_PUBLISH', ['product_id'=>'product-3','expected_version'=>1,'publish_fields'=>['title'=>true]]);
$post('PRODUCT_PUBLISH_FAILED', ['product_id'=>'product-3','expected_version'=>'2','publication_reason'=>'CHANNEL_TIMEOUT']);
$post('ORDER_ADDRESS_CHANGE', ['order_id'=>'order-2','destination'=>'ca_west']);
$post('ORDER_ADDRESS_CHANGE', ['order_id'=>'order-2','destination'=>'ca_west'], 'CONFLICT');
$post('ORDER_ADDRESS_CHANGE', ['order_id'=>'order-1','destination'=>'ca_west'], 'CONFLICT');
$post('SUPPORT_ADDRESS_CHANGE', ['order_id'=>'order-2','destination'=>'us_east']);
$post('SUPPORT_ADDRESS_CHANGE', ['order_id'=>'order-2','destination'=>'us_east'], 'CONFLICT');
$post('SUPPORT_LIST', ['order_id'=>'order-2']);
$post('SUPPORT_DETAIL', ['order_id'=>'order-2','support_request_id'=>'support-order-2-1']);
$post('SUPPORT_DETAIL', ['order_id'=>'order-1','support_request_id'=>'support-order-2-1'], 'NOT_FOUND');
echo "Structured WordPress forms PASS ($checks requests, with execution/outcome/retry flags denied)\n";

$fields = ['external_id'=>'draft-revision-fixture','product_id'=>'product-1','variant_id'=>'9','quantity'=>'1',
    'shipping_method'=>'1','destination'=>'us_east','line_items'=>[
        ['product_id'=>'product-2','variant_id'=>'9','quantity'=>'2'],
    ]];
$initial = $operationResult($send('ORDER_CREATE', $fields, true))['proposal'];
$decide = static function (array $proposal, string $suffix) use ($workspace): array {
    return $workspace->execute($workspace->inspect()['revision'], hash('sha256', $suffix), 'FIXTURE_PROPOSAL_REVIEW',
        ['proposal_id'=>$proposal['id'],'proposal_revision'=>$proposal['revision'],'decision'=>'approve']);
};
$approved = $decide($initial, 'approve original order draft')['proposal'];
requireFixture($approved['state'] === 'APPROVED_HELD', 'Original proposal did not reach held approval');
$before = $workspace->readRecords('orders', null, 1, 10)['data'];
$html = $send('SUPPORT_LIST', ['order_id'=>'order-2']);
requireFixture(str_contains($html,'Save fixture order revision') && str_contains($html,'Choose a fixture destination'),
    'Structured order revision form missing from approval queue');

$change = ['quantity'=>'4','destination'=>'ca_west','line_items'=>[
    ['product_id'=>'product-3','variant_id'=>'10','quantity'=>'3'],
]] + $fields;
$artwork = ['product_id'=>'product-3','expected_version'=>'3','variant_id'=>'9','price'=>'1234',
    'variants'=>[['variant_id'=>'10','price'=>'1300','variant_enabled'=>'enabled']],
    'placements'=>[
        ['variant_id'=>'9','image_id'=>'image-1','position'=>'front','scale'=>'0.8'],
        ['variant_id'=>'10','image_id'=>'image-1','position'=>'back','scale'=>'1.2'],
    ]];
$post('PRODUCT_UPDATE', ['placements'=>[
    ['variant_id'=>'9','image_id'=>'image-1','position'=>'front'],
    ['variant_id'=>'10','image_id'=>'image-1','position'=>'unsupported'],
]]+$artwork, 'VALIDATION');
$post('PRODUCT_UPDATE', $artwork);
$printAreas = $workspace->readRecords('products','product-3', 1, 10)['data']['print_areas'];
requireFixture(array_column($printAreas, 'variant_ids') === [[9],[10]]
    && $printAreas[1]['placeholders'][0]['position'] === 'back', 'Variant-specific artwork mapping lost');
$unavailable = ['line_items'=>[['product_id'=>'product-3','variant_id'=>'11','quantity'=>'3']]] + $change;
$result = $operationResult($send('ORDER_CREATE', $unavailable, true, [], $approved));
requireFixture($result['state'] !== 'SYNTHETIC_OBSERVED' && !isset($result['proposal']), 'Unavailable variant superseded a valid revision');
$result = $operationResult($send('ORDER_CREATE', ['quantity'=>'0']+$change, true, [], $approved));
requireFixture($result['reason'] === 'INVALID_OPERATION_FIELD', 'Zero quantity admitted');
$revised = $operationResult($send('ORDER_CREATE', $change, true, [], $approved))['proposal'];
requireFixture($revised['id'] === $approved['id'] && $revised['revision'] === 3 && $revised['state'] === 'PENDING_APPROVAL'
    && $revised['intent_sha256'] !== $approved['intent_sha256'] && $revised['configuration']['line_items'][0]['quantity'] === 4
    && !isset($revised['configuration']['address_to']), 'Changed order draft retained approval, lost quantities, or stored address');
requireFixture($operationResult($send('ORDER_CREATE', $change, true, [], $approved))['reason'] === 'PROPOSAL_SCOPE_OR_STALE_REVISION',
    'Duplicate draft revision admitted');
requireFixture($operationResult($send('ORDER_CREATE', $fields, false, $approved))['reason'] === 'PROPOSAL_SCOPE_OR_STALE_REVISION',
    'Superseded approval created a fixture order');
requireFixture($decide($initial, 'stale order approval')['reason'] === 'PROPOSAL_SCOPE_OR_STALE_REVISION'
    && $workspace->readRecords('orders', null, 1, 10)['data'] === $before,
    'Stale approval advanced or edited a persisted order');
echo "Structured order editing PASS: line quantities and variant revised, destination corrected, invalid and stale revisions denied\n";

$quote = ['quantity'=>'2','shipping_method'=>'4'] + $change;
foreach (['us_east'=>null,'ca_west'=>null,'au_unavailable'=>'FIXTURE_SHIPPING_UNAVAILABLE'] as $destination=>$reason) {
    $result = $operationResult($send('ORDER_SHIPPING_QUOTE', ['destination'=>$destination]+$quote, true));
    requireFixture($reason === null ? $result['state'] === 'SYNTHETIC_OBSERVED'
        : $result['state'] === 'UNAVAILABLE' && $result['reason'] === $reason, 'Incorrect bounded quote for '.$destination);
}
$result = $operationResult($send('ORDER_SHIPPING_QUOTE', ['destination'=>'ca_west','shipping_method'=>'3']+$quote, true));
requireFixture($result['reason'] === 'FIXTURE_SHIPPING_METHOD_UNAVAILABLE', 'Unavailable express quote was synthesized');

$run('ORDER_CREATE', ['external_id'=>'split-fulfillment-fixture','line_items'=>[
    ['product_id'=>'product-1','variant_id'=>9,'quantity'=>1],
    ['product_id'=>'product-3','variant_id'=>10,'quantity'=>2],
]]+$order);
$run('FIXTURE_PRODUCTION_CONFIRM', ['order_id'=>'order-3']);
$post('FIXTURE_FULFILL', ['order_id'=>'order-3','line_item_id'=>'line-1']);
$partial = $workspace->readRecords('orders', 'order-3', 1, 10)['data'];
requireFixture($partial['status'] === 'partially-fulfilled' && count($partial['shipments']) === 1
    && $partial['line_items'][0]['status'] === 'fulfilled' && ($partial['line_items'][1]['status'] ?? null) !== 'fulfilled',
    'Selected line advanced the entire order');
$post('FIXTURE_FULFILL', ['order_id'=>'order-3','line_item_id'=>'line-1'], 'ORDER_STATE_OR_ID');
$post('FIXTURE_FULFILL', ['order_id'=>'order-3'], 'ORDER_STATE_OR_ID');
$observeShipment = static function (int $sequence, string $type, int $shipment, string $instant, array $extra = [])
    use ($workspace): array {
    $id = sprintf('653b6be8-2ff7-4ab5-a7a6-%012x', $sequence);
    $created = $type === 'created';
    $data = $extra + ['shop_id'=>107, ($created ? 'shipped_at' : 'delivered_at')=>$instant,
        'carrier'=>['code'=>'SYNTHETIC','tracking_number'=>'SYNTHETIC-order-3-shipment-'.$shipment,
            'tracking_url'=>'https://tracking.example.invalid/SYNTHETIC-order-3-shipment-'.$shipment],
        'skus'=>['fixture-sku-'.($shipment === 1 ? '9' : '10')]];
    if ($created) {
        $data += ['line_item_ids'=>['line-'.$shipment]];
    }
    return $workspace->execute($workspace->inspect()['revision'], hash('sha256', $id), 'FIXTURE_WEBHOOK_EVENT', [
        'id'=>$id,'type'=>'order:shipment:'.$type,'created_at'=>'2026-11-19 12:00:00+00:00',
        'resource'=>['id'=>'order-3','type'=>'order','data'=>$data],
    ]);
};
$result = $observeShipment(1, 'delivered', 1, '2026-11-19 10:05:00+00:00');
requireFixture($result['state'] === 'SYNTHETIC_OBSERVED' && $result['shipment_receipt']['order_status'] === 'partially-fulfilled'
    && $result['shipment_receipt']['scope'] === ['shop'=>'107','account'=>'fixture-account','integration'=>'fixture-read']
    && $result['shipment_receipt']['workspace_revision'] === $workspace->inspect()['revision']
    && !$result['provider_execution_authorized'] && !$result['retry_permitted'], 'Partial delivery receipt lost scope or authority hold');
foreach ([
    [2,'created',1,'2026-11-19 10:04:00+00:00',['line_item_ids'=>['line-2']],'EVENT_SHIPMENT_LINE_CONFLICT'],
    [3,'created',1,'2026-11-19 10:06:00+00:00',[],'EVENT_SHIPMENT_TIME_CONFLICT'],
    [4,'delivered',1,'2026-11-19 10:05:00+00:00',[],'DUPLICATE_SHIPMENT_OBSERVATION'],
    [5,'delivered',1,'2026-11-19 10:06:00+00:00',[],'EVENT_DELIVERY_CONFLICT'],
    [6,'created',2,'2026-11-19 10:04:00+00:00',[],'EVENT_SHIPMENT_CONFLICT'],
    [7,'created',1,'2026-11-19 10:04:00+00:00',['shop_id'=>108],'EVENT_CONTRACT_OR_SCOPE'],
] as [$seq,$type,$shipment,$time,$extra,$reason]) {
    $result = $observeShipment($seq, $type, $shipment, $time, $extra);
    requireFixture($result['state'] === 'DENIED' && $result['reason'] === $reason && !isset($result['shipment_receipt']),
        'Invalid shipment observation: '.json_encode($result));
}
requireFixture($observeShipment(8, 'created', 1, '2026-11-19 10:04:00+00:00')['state'] === 'SYNTHETIC_OBSERVED',
    'Delayed creation observation before delivery time was denied');
$partial = $workspace->readRecords('orders', 'order-3', 1, 10)['data'];
$post('FIXTURE_FULFILL', ['order_id'=>'order-3','line_item_id'=>'line-2']);
$complete = $workspace->readRecords('orders', 'order-3', 1, 10)['data'];
requireFixture($complete['status'] === 'fulfilled' && count($complete['shipments']) === 2
    && $complete['shipments'][0] === $partial['shipments'][0], 'Final line replaced the first shipment or failed to advance status');
echo "Structured quotes and split fulfillment PASS: four quotes, duplicate shipment denial and two tracking references\n";

requireFixture($observeShipment(9, 'created', 2, '2026-11-19 10:07:00+00:00')['shipment_receipt']['order_status'] === 'fulfilled',
    'Second receipt did not bind to the completed order');
echo "Shipment receipt PASS: six invalid observations, delayed creation, partial delivery and final receipt\n";
