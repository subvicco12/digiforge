<?php
declare(strict_types=1);

use DigiForgePrintifyApi\Runtime;

$body = '{"id":"order-1","shop_id":107,"status":"fulfilled","line_items":[],"address_to":{"email":"private@example.invalid"}}';
$expected = ['order_id' => 'order-1'];
check(method_exists(Runtime::class, 'reviewProviderRead'), 'Standalone provider routing is missing');
$read = Runtime::reviewProviderRead('107', 'fixture-account', 'ORDER_READ', $expected, $body);
check($read['state'] === 'OFFLINE_PROVIDER_READ_REVIEWED' && $read['request']['method'] === 'GET'
    && $read['observation']['status'] === 'fulfilled' && !isset($read['observation']['address_to'])
    && !$read['provider_outcome_confirmed'] && !$read['provider_execution_authorized']
    && !$read['retry_permitted'] && $read['native_reconciliation'] === 'BLOCKED', 'Wrong typed read disposition');
foreach ([
    ['108','fixture-account','ORDER_READ',$expected,$body],
    ['107','other','ORDER_READ',$expected,$body],
    ['107','fixture-account','ORDER_READ',$expected,str_replace('107','108',$body)],
    ['107','fixture-account','ORDER_READ',['order_id'=>'other'],$body],
    ['107','fixture-account','ORDERS_READ',['page'=>1,'limit'=>11],$body],
    ['107','fixture-account','ORDER_READ',$expected,'{bad'],
    ['107','fixture-account','PRODUCT_PUBLISH',[], '{}'],
    ['107','fixture-account','WEBHOOKS_READ',[], '[]'],
] as $args) {
    check(Runtime::reviewProviderRead(...$args)['state'] === 'DENIED', 'Provider route admitted unsafe input');
}
$plan = Runtime::prepareProviderRead('107', 'fixture-account', 'ORDERS_READ', ['limit'=>2]);
check($plan['state'] === 'OFFLINE_PROVIDER_READ_PREPARED' && !$plan['provider_execution_authorized'], 'Read planning failed');
$page = Runtime::reviewProviderRead('107', 'fixture-account', 'ORDERS_READ', ['limit'=>2],
    '{"data":[],"current_page":1,"last_page":2,"per_page":2,"next_page_url":"https://example.invalid"}');
check($page['observation']['next_page'] === ['page'=>2,'limit'=>2]
    && !$page['observation']['pagination_followed'], 'Pagination escaped local projection');
check(Runtime::reviewProviderRead('107', 'fixture-account', 'ORDERS_READ', ['limit'=>2],
    '{"data":[{"shop_id":108}],"current_page":1}')['state'] === 'DENIED', 'Foreign scoped row admitted');
$shops = Runtime::reviewProviderRead('107', 'fixture-account', 'SHOPS_READ', [],
    '[{"id":107,"title":"Expected","sales_channel":"etsy"},{"id":108,"title":"Private","sales_channel":"etsy"}]');
check(array_column($shops['observation']['shops'], 'id') === [107], 'Other shop was disclosed');
$product = ['id'=>'product-1','shop_id'=>107,'blueprint_id'=>42,'print_provider_id'=>7,
    'variants'=>[['id'=>9,'price'=>100,'is_enabled'=>true]],'print_areas'=>[]];
$productScope = ['product_id'=>'product-1','blueprint_id'=>42,'provider_id'=>7,'variant_ids'=>[9]];
foreach ([
    ['PROVIDERS_READ', [], [['id'=>7,'title'=>'Fixture']]],
    ['PROVIDER_READ', ['provider_id'=>7], ['id'=>7,'title'=>'Fixture']],
    ['PRODUCTS_READ', [], ['data'=>[$product],'current_page'=>1,'last_page'=>1,'per_page'=>10]],
    ['PRODUCT_READ', $productScope, $product],
    ['PRODUCT_GPSR_READ', $productScope, [['title'=>'Fixture','text'=>'Evidence']]],
] as [$operation, $input, $fixture]) {
    $review = Runtime::reviewProviderRead('107', 'fixture-account', $operation, $input, json_encode($fixture, JSON_THROW_ON_ERROR));
    check($review['state'] === 'OFFLINE_PROVIDER_READ_REVIEWED' && $review['request']['method'] === 'GET'
        && !$review['provider_execution_authorized'], 'Read projection unavailable for ' . $operation);
}
$GLOBALS['fixture_caps'] = [];
check(Runtime::prepareProviderRead('107', 'fixture-account', 'ORDER_READ', $expected)['state'] === 'DENIED',
    'Revoked operator retained routing access');
$GLOBALS['fixture_caps'] = ['manage_options','digiforge_printify_review_shop_107'];
