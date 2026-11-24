<?php
declare(strict_types=1);

use DigiForgePrintifyApi\SyntheticPrintifyClient;
use DigiForgePrintifyApi\SyntheticPrintifyTransport;

define('ABSPATH', __DIR__.'/fixture-wordpress/');
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
$plugin = $argv[1];
require_once $plugin.'/SyntheticPrintifyTransport.php';
require_once $plugin.'/SyntheticPrintifyClient.php';
$client = new SyntheticPrintifyClient('107', new SyntheticPrintifyTransport('107'));
if ($client->run('SHOPS_READ')['state'] !== 'SYNTHETIC_OBSERVED') {
    throw new RuntimeException('Exact extracted fixture client required');
}
$project = new ReflectionMethod($client, 'project');

$catalog = ['blueprint_id'=>42,'provider_id'=>7];
$productId = ['product_id'=>'product-1'];
$orderId = ['order_id'=>'order-1'];
$imageId = ['image_id'=>'image-1'];
$product = ['id'=>'product-1','shop_id'=>107,'blueprint_id'=>42,'print_provider_id'=>7,'synthetic_version'=>1,
    'variants'=>[['id'=>9,'price'=>1000,'is_enabled'=>true]],'print_areas'=>[]];
$orderInput = ['external_id'=>'fixture','line_items'=>[['product_id'=>'product-1','variant_id'=>9,'quantity'=>2]]];
$order = ['id'=>'order-1','shop_id'=>107,'external_id'=>'fixture','status'=>'on-hold',
    'line_items'=>[['product_id'=>'product-1','variant_id'=>9,'quantity'=>2]]];

$cases = [
    ['SHOPS_READ', [], [['id'=>108,'title'=>'Other','sales_channel'=>'api']]],
    ['BLUEPRINT_READ', ['blueprint_id'=>42], ['id'=>43,'title'=>'Wrong blueprint']],
    ['BLUEPRINTS_READ', [], [['id'=>null,'title'=>'No identity']]],
    ['BLUEPRINT_PROVIDER_READ', $catalog, ['id'=>8,'title'=>'Other printer']],
    ['PROVIDERS_READ', [], [['id'=>7,'title'=>'Printer'],['id'=>7,'title'=>'Duplicate']]],
    ['VARIANTS_READ', $catalog, ['variants'=>[['id'=>9,'title'=>'Fixture','options'=>[],'placeholders'=>[
        ['position'=>'front','width'=>0,'height'=>1200],
    ]]]]],
    ['VARIANT_READ', $catalog+['variant_id'=>10], ['variants'=>[['id'=>9,'title'=>'Wrong variant','options'=>[]]]]],
    ['CATALOG_SHIPPING_METHOD_READ', $catalog+['method'=>'economy'], ['profiles'=>[['variant_ids'=>[9],'first_item'=>-1]]]],
    ['IMAGE_READ', $imageId, ['id'=>'image-other']],
    ['IMAGE_UPLOAD', ['file_name'=>'fixture.png'], ['id'=>'image-1','file_name'=>'wrong.png'], [], 201],
    ['UPLOADS_READ', [], ['data'=>[],'current_page'=>2,'per_page'=>10,'last_page'=>1], ['input'=>['page'=>1,'limit'=>10]]],
    ['PRODUCT_READ', $catalog+$productId+['variant_ids'=>[9]], ['synthetic_publication_state'=>'PUBLISHED',
        'synthetic_publish_pending'=>true]+$product],
    ['PRODUCT_READ', $catalog+$productId+['variant_ids'=>[9]], ['print_areas'=>[
        ['variant_ids'=>[10],'placeholders'=>[]],
    ]]+$product],
    ['PRODUCT_CREATE', [], ['id'=>'product-1'], ['body'=>[]], 201],
    ['PRODUCT_CREATE', [], $product, ['body'=>['blueprint_id'=>43]], 201],
    ['PRODUCT_UPDATE', $productId, $product, ['expected_version'=>1,'body'=>[]]],
    ['PRODUCT_PUBLISH', $productId, ['unexpected'=>true]],
    ['PRODUCT_PUBLISH_SUCCEEDED', $productId, [], [], 202],
    ['PRODUCT_PUBLISH_FAILED', $productId, ['id'=>'product-1']],
    ['PRODUCT_UNPUBLISH_NOTIFY', $productId, [], [], 201],
    ['ORDER_SHIPPING_QUOTE', [], ['express'=>100,'priority'=>200]],
    ['ORDER_SHIPPING_QUOTE', [], ['standard'=>-1]],
    ['ORDER_CREATE', $orderInput, ['line_items'=>[['product_id'=>'product-1','variant_id'=>9,'quantity'=>1]]]+$order, [], 201],
    ['ORDER_CREATE', $orderInput, ['external_id'=>'different']+$order, [], 201],
    ['ORDER_SEND_TO_PRODUCTION', $orderId, $order],
    ['ORDER_CANCEL', $orderId, $order, ['endpoint'=>'/v1/shops/107/orders/order-1/cancel.json']],
    ['LINE_ITEM_CANCEL', [], ['bad'=>true], ['endpoint'=>'/v1/shops/107/orders/order-1/line_items/line-1/cancel.json'], 204],
];
foreach ($cases as $case) {
    [$selector,$input,$body] = $case;
    $plan = ($case[3] ?? []) + ['operation'=>$selector,'provider_shop_id'=>'107'];
    try {
        $project->invoke($client, $selector, $input, $plan, $body, $case[4] ?? 200);
    } catch (InvalidArgumentException) {
        echo "$selector\tDENIED\n";
        continue;
    }
    throw new RuntimeException('Malformed response admitted for '.$selector);
}
echo 'Response qualification PASS ('.count($cases)." malformed responses)\n";
