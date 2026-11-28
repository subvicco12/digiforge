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
    ['SHOPS_READ', [], [['id'=>107,'title'=>'Fixture','sales_channel'=>'api']], ['method'=>'GET'], 201],
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
    ['MOCKUP_SELECTION_READ', $productId, [[
        'mockup_id'=>'mockup-1','src'=>'http://images.example.invalid/fixture.png',
        'type'=>'GENERATED','selected'=>true,'variant_ids'=>[9],
    ]]],
    ['MOCKUP_SELECTION_UPDATE', $productId+['selection_sha256'=>str_repeat('a',64)], ['unexpected'=>true], [], 204],
    ['MOCKUP_SELECTION_UPDATE', $productId+['selection_sha256'=>str_repeat('a',64)], [], [], 200],
    ['PRODUCT_CREATE', [], ['title'=>'Changed in response']+$product, ['body'=>['title'=>'Approved title']], 201],
    ['PRODUCT_UPDATE', $productId, ['description'=>'Different artwork description','synthetic_version'=>2]+$product,
        ['expected_version'=>1,'body'=>['description'=>'Approved description']]],
    ['ORDERS_READ', [], ['shop_id'=>108,'data'=>[],'current_page'=>1]],
    ['ORDER_READ', $orderId, ['shipments'=>[[
        'carrier'=>'Fixture','number'=>'fixture-tracking','url'=>'https://tracking.example.invalid/one','line_item_ids'=>['foreign-line'],
    ]], 'line_items'=>[['id'=>'line-1','product_id'=>'product-1','variant_id'=>9,'quantity'=>2]]]+$order],
    ['ORDER_READ', $orderId, ['shipments'=>[[
        'carrier'=>'Fixture','number'=>'fixture-tracking','url'=>'https://tracking.example.invalid/one','line_item_ids'=>['line-1','line-1'],
    ]], 'line_items'=>[['id'=>'line-1','product_id'=>'product-1','variant_id'=>9,'quantity'=>2]]]+$order],
    ['PRODUCT_CREATE', [], ['id'=>'product-1'], ['body'=>[]], 201],
    ['PRODUCT_CREATE', [], $product, ['body'=>['blueprint_id'=>43]], 201],
    ['PRODUCT_UPDATE', $productId, $product, ['expected_version'=>1,'body'=>[]]],
    ['PRODUCT_PUBLISH', $productId, ['unexpected'=>true]],
    ['PRODUCT_PUBLISH_SUCCEEDED', $productId, [], [], 202],
    ['PRODUCT_PUBLISH_FAILED', $productId, ['id'=>'product-1']],
    ['PRODUCT_PUBLISH_SUCCEEDED', $productId, ['product_id'=>'product-foreign']],
    ['PRODUCT_PUBLISH_FAILED', $productId, []],
    ['PRODUCT_UNPUBLISH_NOTIFY', $productId, [], [], 201],
    ['ORDER_SHIPPING_QUOTE', [], ['express'=>100,'priority'=>200]],
    ['ORDER_SHIPPING_QUOTE', [], ['standard'=>-1]],
    ['ORDER_CREATE', $orderInput, ['line_items'=>[['product_id'=>'product-1','variant_id'=>9,'quantity'=>1]]]+$order, [], 201],
    ['ORDER_CREATE', $orderInput, ['external_id'=>'different']+$order, [], 201],
    ['ORDER_SEND_TO_PRODUCTION', $orderId, $order],
    ['ORDER_CANCEL', $orderId, ['status'=>'canceled']+$order, ['endpoint'=>'/v1/shops/107/orders/order-1/cancel.json'], 202],
    ['ORDER_CANCEL', $orderId, $order, ['endpoint'=>'/v1/shops/107/orders/order-1/cancel.json']],
    ['LINE_ITEM_CANCEL', [], ['bad'=>true], ['endpoint'=>'/v1/shops/107/orders/order-1/line_items/line-1/cancel.json'], 204],
];
$webhook = ['id'=>'webhook-1','topic'=>'order:created','shop_id'=>107,'url'=>'https://webhooks.example.invalid/printify'];
$webhooks = '/v1/shops/107/webhooks';
$personalization = '/v1/shops/107/products/product-1/personalization';
$cases = array_merge($cases, [
    ['BLUEPRINT_PROVIDERS_READ', ['blueprint_id'=>42], [['id'=>7],['id'=>7]]],
    ['PROVIDER_READ', ['provider_id'=>7], ['id'=>8,'title'=>'Other provider']],
    ['SIZE_GUIDE_READ', ['blueprint_id'=>42], ['sizes'=>['S','S'],'types'=>[]]],
    ['CATALOG_SHIPPING_READ', $catalog, ['data'=>[],'links'=>['standard'=>'https://wrong.example.invalid']]],
    ['CATALOG_SHIPPING_V1_READ', $catalog, ['handling_time'=>['value'=>2,'unit'=>'days'],'profiles'=>['malformed']]],
    ['IMAGE_ARCHIVE', $imageId, [], [], 201],
    ['PRODUCTS_READ', [], ['data'=>[$product,$product],'current_page'=>1,'per_page'=>10,'last_page'=>1]],
    ['PRODUCT_GPSR_READ', $catalog+$productId+['variant_ids'=>[9]], [['title'=>[], 'text'=>'Malformed']]],
    ['PRODUCT_DELETE', $productId, ['id'=>'different']],
    ['ORDER_READ', $orderId, ['id'=>'order-2']+$order],
    ['ORDERS_READ', [], ['data'=>[$order,$order],'current_page'=>1]],
    ['WEBHOOKS_READ', [], [$webhook,$webhook], ['endpoint'=>$webhooks.'.json','body'=>[]]],
    ['WEBHOOK_CREATE', [], ['shop_id'=>108]+$webhook, ['endpoint'=>$webhooks.'.json','body'=>[]], 201],
    ['WEBHOOK_UPDATE', [], ['id'=>'webhook-2']+$webhook, ['endpoint'=>$webhooks.'/webhook-1.json',
        'body'=>[],'input'=>['webhook_id'=>'webhook-1']]],
    ['WEBHOOK_DELETE', [], ['id'=>'webhook-2'], ['endpoint'=>$webhooks.'/webhook-1.json?host=webhooks.example.invalid']],
    ['PERSONALIZATION_OPTIONS', $productId, [['field_id'=>'name','type'=>'unknown']], ['body'=>[],'endpoint'=>$personalization.'_options.json']],
    ['PERSONALIZATION_CONFIGURE', $productId, ['personalisation_strategy'=>false], ['body'=>[],'endpoint'=>$personalization.'.json'], 201],
    ['PREVIEW_CREATE', $productId, ['status'=>'pending'], ['body'=>[],'endpoint'=>$personalization.'_previews.json'], 202],
    ['PREVIEW_READ', $productId, ['task_id'=>'wrong-task','status'=>'pending'],
        ['body'=>[],'endpoint'=>$personalization.'_previews/tasks/preview-1.json']],
    ['SHOP_DISCONNECT', [], ['unexpected'=>true]],
    ['ORDER_EXPRESS_CREATE', $orderInput, ['data'=>[]]],
    ['WEBHOOK_SIMULATE', [], ['unexpected'=>true], [], 204],
]);
$prepare = new ReflectionMethod($client, 'prepare');
$address = $orderId+['first_name'=>'Fixture','last_name'=>'Buyer','address1'=>'2 Fixture Street','city'=>'Testville','country'=>'US','zip'=>'90210'];
$supportItems = $orderId+['reason'=>'wrong_design','description'=>'Fixture','line_items'=>[['line_item_id'=>'line-1','quantity'=>1]]];
foreach ([
    ['SUPPORT_LIST',$orderId,['data'=>null],200],
    ['SUPPORT_DETAIL',$orderId+['support_request_id'=>'support-order-1-1'],['data'=>null],200],
    ['SUPPORT_REFUND',$supportItems,['id'=>null],201],
    ['SUPPORT_REPRINT',$supportItems,['id'=>null],201],
    ['SUPPORT_ADDRESS_CHANGE',$address,['resolution'=>'updated_directly','order_id'=>'wrong-order'],200],
    ['ORDER_ADDRESS_CHANGE',$address,['resolution'=>'updated_directly','order_id'=>'wrong-order'],200],
] as [$selector,$input,$body,$status]) {
    $cases[] = [$selector,$input,$body,$prepare->invoke($client,$selector,$input),$status];
}
$pixel = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGNgYPgPAAEDAQAIicLsAAAAAElFTkSuQmCC';
$cases[] = ['IMAGE_UPLOAD', ['file_name'=>'fixture.png'], [
    'id'=>'image-1','file_name'=>'fixture.png','width'=>2,'height'=>1,
    'size'=>strlen(base64_decode($pixel,true)),'mime_type'=>'image/png',
], ['body'=>['contents'=>$pixel]], 201];
foreach (['standard','priority','express','economy'] as $method) {
    $cases[] = ['CATALOG_SHIPPING_METHOD_READ',$catalog+['method'=>$method],['data'=>[['id'=>'9','type'=>'wrong-type',
        'attributes'=>['shippingType'=>$method,'country'=>['code'=>'US'],'variantId'=>9]]]]];
}
if (array_diff($client::operations(), array_unique(array_column($cases, 0))) !== []) {
    throw new RuntimeException('Missing reviewed selector response case');
}
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

foreach (['standard','priority','express','economy'] as $method) {
    $result = $client->run('CATALOG_SHIPPING_METHOD_READ', $catalog+['method'=>$method]);
    if ($result['state'] !== 'SYNTHETIC_OBSERVED' || $result['provider_execution_authorized']
        || ($method === 'standard' ? count($result['data']['data']) !== 1 : $result['data']['data'] !== [])) {
        throw new RuntimeException('Unexpected fixture rate for '.$method);
    }
}
echo "Catalog method availability PASS: standard fixture rate and three empty observations\n";
