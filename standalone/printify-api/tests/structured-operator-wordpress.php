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
