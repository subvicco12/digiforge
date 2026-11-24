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
$third = $product;
$third['variants'][] = ['id'=>10,'price'=>1300,'is_enabled'=>true];
$third['print_areas'][0]['variant_ids'][] = 10;
$run('PRODUCT_UPDATE', ['product_id'=>'product-3','expected_version'=>3,
    'variants'=>$third['variants'],'print_areas'=>$third['print_areas']]);
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
