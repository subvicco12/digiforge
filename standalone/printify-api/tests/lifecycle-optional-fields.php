<?php
declare(strict_types=1);

use DigiForgePrintifyApi\SyntheticPrintifyClient;
use DigiForgePrintifyApi\SyntheticPrintifyTransport;
use DigiForgePrintifyApi\Engine\POD\PrintifyApiOutcome;

define('ABSPATH', __DIR__.'/fixture-wordpress/');
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
$plugin = $argv[1];
require $plugin.'/SyntheticPrintifyTransport.php';
require $plugin.'/SyntheticPrintifyClient.php';
$transport = new SyntheticPrintifyTransport('107');
$client = new SyntheticPrintifyClient('107',$transport);
$line = ['product_id'=>'product-1','variant_id'=>9,'quantity'=>1];
$order = ['external_id'=>'optional-fixture','line_items'=>[$line],'shipping_method'=>1,
    'send_shipping_notification'=>false,'is_printify_express'=>false,'is_economy_shipping'=>false,
    'address_to'=>['first_name'=>'Fixture','last_name'=>'Only','email'=>'fixture@example.invalid','phone'=>'','country'=>'US',
        'region'=>'CA','address1'=>'Fixture street','address2'=>'','city'=>'Fixture','zip'=>'90210']];
$express = array_diff_key($order,array_fill_keys(['shipping_method','is_printify_express','is_economy_shipping'],true));
$before = $transport->exportFixtureState();
$badRequests = [
    ['ORDER_CREATE',['is_printify_express'=>true,'is_economy_shipping'=>true]+$order],
    ['ORDER_CREATE',['label'=>null]+$order],
    ['ORDER_CREATE',['line_items'=>[['external_id'=>null]+$line]]+$order],
    ['ORDER_SHIPPING_QUOTE',['order'=>['shipping_method'=>4,'is_economy_shipping'=>false]+$order]],
    ['ORDER_EXPRESS_CREATE',['shipping_method'=>4]+$express],
    ['ORDER_EXPRESS_CREATE',['send_shipping_notification'=>true]+$express],
    ['ORDER_CANCEL',['order_id'=>'order-1','line_item_id'=>'line-1']],
    ['PRODUCT_PUBLISH_SUCCEEDED',['product_id'=>'product-1','external'=>['id'=>'channel-1','handle'=>'https://fixture.example.invalid'],
        'reason'=>'conflicting callback']],
    ['WEBHOOK_DELETE',['webhook_id'=>'webhook-1','url'=>'https://webhooks.example.invalid/printify']],
];
foreach ($badRequests as [$selector,$input]) {
    $result = $client->run($selector,$input);
    if ($result['state'] !== 'DENIED' || $result['reason'] !== 'REQUEST_VALIDATION' || $result['retry_permitted']
        || $result['provider_execution_authorized'] || $transport->admittedRequests() !== [] || $transport->exportFixtureState() !== $before) {
        throw new RuntimeException('Conflicting request admitted: '.$selector);
    }
}
$project = new ReflectionMethod($client,'project');
$product = ['id'=>'product-1','shop_id'=>107,'blueprint_id'=>42,'print_provider_id'=>7,'synthetic_version'=>2,
    'synthetic_publish_pending'=>false,'synthetic_publication_state'=>'PUBLISHED','visible'=>true,
    'variants'=>[['id'=>9,'price'=>1000,'is_enabled'=>true]],'print_areas'=>[]];
$productInput = ['product_id'=>'product-1','blueprint_id'=>42,'provider_id'=>7,'variant_ids'=>[9]];
$created = ['id'=>'order-1','status'=>'on-hold','shop_id'=>107,'external_id'=>'optional-fixture','line_items'=>[$line],
    'shipping_method'=>1,'send_shipping_notification'=>false];
$expressLine = $line+['id'=>'line-1','status'=>'pending','cost'=>100,'shipping_cost'=>20];
$expressResult = ['type'=>'order','id'=>'order-2','shop_id'=>107,'attributes'=>[
    'fulfilment_type'=>'express','external_id'=>'optional-fixture','line_items'=>[$expressLine],
]];
$badResponses = [
    ['PRODUCT_READ',$productInput,['visible'=>false]+$product,200],
    ['PRODUCT_READ',$productInput,['synthetic_publication_state'=>null]+$product,200],
    ['ORDER_CREATE',$order,['shipping_method'=>'1']+$created,201],
    ['ORDER_CREATE',$order,['send_shipping_notification'=>true]+$created,201],
    ['ORDER_EXPRESS_CREATE',$express,['data'=>[['shop_id'=>108]+$expressResult]],200],
    ['ORDER_EXPRESS_CREATE',$express,['shop_id'=>108,'data'=>[$expressResult]],200],
    ['ORDER_EXPRESS_CREATE',$express,['data'=>[['attributes'=>['external_id'=>'other-order']+$expressResult['attributes']]+$expressResult]],200],
    ['ORDER_EXPRESS_CREATE',['line_items'=>[$line,$line]]+$express,
        ['data'=>[['attributes'=>['line_items'=>[$expressLine,$expressLine]]+$expressResult['attributes']]+$expressResult]],200],
];
foreach ($badResponses as [$selector,$input,$body,$status]) {
    try {
        $project->invoke($client,$selector,$input,['operation'=>$selector,'provider_shop_id'=>'107'],$body,$status);
    } catch (InvalidArgumentException) {
        continue;
    }
    throw new RuntimeException('Conflicting response admitted: '.$selector);
}
foreach ([
    ['PRODUCT_READ',$productInput,$product,200,'id','product-1'],
    ['ORDER_CREATE',$order,$created,201,'id','order-1'],
    ['ORDER_EXPRESS_CREATE',$express,['data'=>[$expressResult]],200,'orders',[
        ['id'=>'order-2','fulfilment_type'=>'express','line_items'=>[$expressLine]],
    ]],
] as [$selector,$input,$body,$status,$field,$expected]) {
    $result = $project->invoke($client,$selector,$input,['operation'=>$selector,'provider_shop_id'=>'107'],$body,$status);
    if ($result[$field] !== $expected) {
        throw new RuntimeException('Valid identity lost after projection: '.$selector);
    }
}
$transport->faultNext('lost_ack');
$result = $client->run('SHOP_DISCONNECT');
if ($result['state'] !== 'UNKNOWN' || $result['retry_permitted'] || $result['provider_execution_authorized']
    || $result['provider_outcome_confirmed'] || count($transport->admittedRequests()) !== 1
    || PrintifyApiOutcome::classify(0,[],1,'POST')['retry_permitted']) {
    throw new RuntimeException('Ambiguous acknowledgment granted retry or external authority');
}
echo "Lifecycle optional-field qualification PASS: nine pre-admission denials, ".count($badResponses)." response conflicts, three valid identities and one UNKNOWN\n";
