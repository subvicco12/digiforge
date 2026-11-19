<?php
declare(strict_types=1);

use DigiForgePrintifyApi\SyntheticPrintifyTransport;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__.'/');
}
require_once ($argv[1] ?? __DIR__.'/../digiforge-printify-api').'/SyntheticPrintifyTransport.php';

function requireSynthetic(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$transport = new SyntheticPrintifyTransport('107');
$call = static function (string $operation, string $method, string $endpoint, array $body = [],
    array $fixture = []) use ($transport): array {
    $response = $transport->request($fixture + ['operation'=>$operation, 'method'=>$method, 'endpoint'=>$endpoint,
        'body'=>$body, 'provider_shop_id'=>'107']);
    requireSynthetic($response['synthetic'] && !$response['provider_execution_authorized'] && !$response['retry_permitted'],
        'An observation escaped the synthetic boundary.');
    return $response;
};
$root = '/v1/shops/107';
requireSynthetic($call('SHOPS_READ','GET','https://api.printify.com/v1/shops.json')['status']===404,
    'A physical destination escaped the route registry.');
requireSynthetic($call('SHOPS_READ','GET','/v1/shops.json',[],['provider_shop_id'=>'108'])['status']===403,
    'Foreign shop escaped the scope gate.');
foreach (['timeout'=>0,'rate_limit'=>429,'validation_error'=>422] as $fault=>$expected) {
    $transport->faultNext($fault);
    requireSynthetic($call('SHOPS_READ','GET','/v1/shops.json')['status']===$expected, 'Incorrect fixture fault.');
}
requireSynthetic($call('SHOPS_READ','GET','/v1/shops.json')['body'][0]['id']===107, 'Fault was not one-shot.');

$avatar = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3ZkAAAAASUVORK5CYII=';
$image = $call('IMAGE_UPLOAD','POST','/v1/uploads/images.json',['file_name'=>'fixture.png','contents'=>$avatar]);
requireSynthetic($image['status']===201 && $image['body']['id']==='image-1','Synthetic image was not stored.');
$productBody = ['title'=>'Synthetic mug','description'=>'Synthetic only','blueprint_id'=>42,'print_provider_id'=>7,
    'variants'=>[['id'=>9,'price'=>1000,'is_enabled'=>true]],
    'print_areas'=>[['variant_ids'=>[9],'placeholders'=>[['position'=>'front','images'=>[
        ['id'=>'image-1','x'=>0.5,'y'=>0.5,'scale'=>1,'angle'=>0],
    ]]]]]];
$created = $call('PRODUCT_CREATE','POST',$root.'/products.json',$productBody);
requireSynthetic($created['status']===201 && $created['body']['id']==='product-1'
    && $created['body']['synthetic_version']===1, 'Synthetic product was not stored.');
$updated = $call('PRODUCT_UPDATE','PUT',$root.'/products/product-1.json',['title'=>'New title'],['expected_version'=>1]);
requireSynthetic($updated['status']===200 && $updated['body']['synthetic_version']===2,'Product revision did not advance.');
requireSynthetic($call('PRODUCT_UPDATE','PUT',$root.'/products/product-1.json',['title'=>'Stale'],['expected_version'=>1])['status']===409,
    'Stale revision altered the fixture.');

$line = ['product_id'=>'product-1','variant_id'=>9,'quantity'=>1];
$order = ['external_id'=>'fixture-order','line_items'=>[$line],'address_to'=>['country'=>'US']];
requireSynthetic($call('ORDER_SHIPPING_QUOTE','POST',$root.'/orders/shipping.json',$order)['body']['standard']===500,
    'Shipping cost did not reflect the synthetic catalog.');
$transport->faultNext('lost_ack');
requireSynthetic($call('ORDER_CREATE','POST',$root.'/orders.json',$order)['status']===0,
    'Lost acknowledgment was not ambiguous.');
requireSynthetic($call('ORDER_CREATE','POST',$root.'/orders.json',$order)['status']===409,
    'Lost acknowledgment created a duplicate order.');
$first = $call('ORDER_READ','GET',$root.'/orders/order-1.json');
requireSynthetic($first['body']['external_id']==='fixture-order' && $first['body']['status']==='on-hold',
    'Committed order could not be read after lost acknowledgment.');
requireSynthetic($call('ORDER_SEND_TO_PRODUCTION','POST',$root.'/orders/order-1/send_to_production.json')['status']===409
    && !$transport->fulfill('order-1'), 'Production gate was bypassed.');
requireSynthetic($transport->confirmProductionSimulation('order-1'), 'Explicit fixture production could not be selected.');
requireSynthetic($call('ORDER_SEND_TO_PRODUCTION','POST',$root.'/orders/order-1/send_to_production.json')['status']===200
    && $transport->fulfill('order-1'), 'Synthetic production/fulfillment failed.');
$tracked = $call('ORDER_READ','GET',$root.'/orders/order-1.json');
requireSynthetic($tracked['body']['line_items']===$first['body']['line_items'] && $tracked['body']['status']==='fulfilled'
    && count($tracked['body']['shipments'])===1 && !isset($tracked['body']['address_to']), 'Tracking readback changed order lineage.');
requireSynthetic($call('ORDER_CANCEL','POST',$root.'/orders/order-1/cancel.json')['status']===409,
    'An ineligible order was canceled.');
$second = $call('ORDER_CREATE','POST',$root.'/orders.json',array_replace($order,['external_id'=>'fixture-cancel']));
requireSynthetic($second['status']===201 && $call('ORDER_CANCEL','POST',$root.'/orders/order-2/cancel.json')['body']['status']==='canceled',
    'An eligible order could not be canceled.');
$page = $call('ORDERS_READ','GET',$root.'/orders.json?page=2&limit=1');
requireSynthetic(array_column($page['body']['data'],'id')===['order-2'], 'Pagination did not respect bounded selectors.');

$webhook = ['topic'=>'order:created','url'=>SyntheticPrintifyTransport::WEBHOOK_URLS[0]];
requireSynthetic($call('WEBHOOK_CREATE','POST',$root.'/webhooks.json',array_replace($webhook,
    ['url'=>'https://outside.example.com']))['status']===422, 'An external webhook destination escaped the fixture.');
requireSynthetic($call('WEBHOOK_CREATE','POST',$root.'/webhooks.json',$webhook+['secret'=>'not-allowed'])['status']===422,
    'Webhook fixture accepted signing material.');
$sub = $call('WEBHOOK_CREATE','POST',$root.'/webhooks.json',$webhook);
requireSynthetic($sub['status']===201 && $sub['body']['id']==='webhook-1', 'Webhook fixture was not stored.');
requireSynthetic($call('WEBHOOK_CREATE','POST',$root.'/webhooks.json',$webhook)['status']===409, 'Duplicate subscription was stored.');
$url = SyntheticPrintifyTransport::WEBHOOK_URLS[1];
requireSynthetic($call('WEBHOOK_UPDATE','PUT',$root.'/webhooks/webhook-1.json',['url'=>$url])['status']===200,
    'Webhook fixture could not be updated.');
requireSynthetic($call('WEBHOOK_READ','GET',$root.'/webhooks.json')['body'][0]['url']===$url, 'Webhook update was not read back.');
requireSynthetic($call('WEBHOOK_DELETE','DELETE',$root.'/webhooks/webhook-1.json?host=webhooks.example.invalid')['status']===200
    && $call('WEBHOOK_READ','GET',$root.'/webhooks.json')['body']===[], 'Webhook fixture could not be removed.');

$executed=false;
$object=new class($executed) implements JsonSerializable {
    public function __construct(private bool &$executed) {}
    public function jsonSerialize(): mixed { $this->executed=true; return []; }
};
requireSynthetic($call('PRODUCT_CREATE','POST',$root.'/products.json',$productBody+['unexpected'=>$object])['status']===422
    && !$executed,'A transport request could execute a user-supplied serializer.');

echo "Synthetic Printify transport fault and lifecycle fixtures PASS\n";
