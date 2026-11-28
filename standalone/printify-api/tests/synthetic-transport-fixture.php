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

$mockupPath = $root.'/products/product-1/mockup-selection.json';
$replacement = ['groups'=>[['variant_ids'=>[9],'mockup_ids'=>['product-1_back']]]];
requireSynthetic($call('MOCKUP_SELECTION_UPDATE','PATCH',$mockupPath,$replacement,['expected_version'=>2])['status']===204
    && $call('MOCKUP_SELECTION_READ','GET',$mockupPath)['body'][0]['mockup_id']==='product-1_back',
    'Complete mockup replacement did not reopen.');
$art = $productBody['print_areas'];
$art[0]['placeholders'][0]['images'][0]['x']=0.4;
requireSynthetic($call('PRODUCT_UPDATE','PUT',$root.'/products/product-1.json',['print_areas'=>$art],['expected_version'=>3])['status']===200
    && $call('MOCKUP_SELECTION_READ','GET',$mockupPath)['body'][0]['mockup_id']==='product-1_front'
    && $call('MOCKUP_SELECTION_UPDATE','PATCH',$mockupPath,$replacement,['expected_version'=>3])['status']===409,
    'Artwork edit retained the previous mockup selection or admitted its stale revision.');

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

$third = array_replace($order, ['external_id'=>'fixture-multiple', 'line_items'=>[$line,$line,$line]]);
requireSynthetic($call('ORDER_CREATE','POST',$root.'/orders.json',$third+['shipping_method'=>99])['status']===422,
    'Unknown shipping method created a fixture order.');
requireSynthetic($call('ORDER_CREATE','POST',$root.'/orders.json',$third+['shipping_method'=>2])['status']===201,
    'Valid selected shipping method did not create an order.');
$linePath = $root.'/orders/order-3/line_items/line-2/cancel.json';
requireSynthetic($call('LINE_ITEM_CANCEL','POST',$linePath)['status']===204
    && $call('LINE_ITEM_CANCEL','POST',$linePath)['status']===404
    && $transport->confirmProductionSimulation('order-3')
    && $call('ORDER_SEND_TO_PRODUCTION','POST',$root.'/orders/order-3/send_to_production.json')['status']===200
    && $transport->fulfill('order-3','line-3') && !$transport->fulfill('order-3','line-2'),
    'Line cancellation or subsequent production crossed fixture state boundaries.');
$orderPath = $root.'/orders/order-3.json';
requireSynthetic($call('ORDER_READ','GET',$orderPath)['body']['status']==='partially-fulfilled'
    && $transport->fulfill('order-3','line-1') && !$transport->fulfill('order-3','line-1'),
    'Partial fulfillment or duplicate shipment handling failed.');
$shipmentRead = $call('ORDER_READ','GET',$orderPath)['body'];
requireSynthetic($shipmentRead['status']==='fulfilled'
    && array_column($shipmentRead['shipments'],'line_item_ids')===[['line-3'],['line-1']]
    && count(array_unique(array_column($shipmentRead['shipments'],'number')))===2,
    'Distinct shipments failed exact order readback.');

$fourth = $call('PRODUCT_CREATE','POST',$root.'/products.json',['title'=>'Fixture release candidate']+$productBody);
requireSynthetic($fourth['status']===201,'Independent publication fixture was not created.');
$release = $root.'/products/product-2';
requireSynthetic($call('PRODUCT_PUBLISH','POST',$release.'/publish.json',['title'=>true],['expected_version'=>1])['status']===200
    && $call('PRODUCT_DELETE','DELETE',$release.'.json',[],['expected_version'=>2])['status']===409,
    'A pending publication did not hold deletion.');
requireSynthetic($call('PRODUCT_PUBLISH_SUCCEEDED','POST',$release.'/publishing_succeeded.json',
    ['external'=>['id'=>'fixture-published','handle'=>'https://products.example.invalid/fixture-published']],['expected_version'=>2])['status']===200,
    'Fixture publication acknowledgment was rejected.');
$published = $call('PRODUCT_READ','GET',$release.'.json')['body'];
requireSynthetic($published['synthetic_version']===3 && $published['synthetic_publication_state']==='PUBLISHED'
    && $call('PRODUCT_DELETE','DELETE',$release.'.json',[],['expected_version'=>3])['status']===409
    && $call('PRODUCT_UNPUBLISH_NOTIFY','POST',$release.'/unpublish.json',[],['expected_version'=>3])['status']===200,
    'Published fixture state or deletion gate was incorrect.');
requireSynthetic($call('PRODUCT_DELETE','DELETE',$release.'.json',[],['expected_version'=>3])['status']===409
    && $call('PRODUCT_DELETE','DELETE',$release.'.json',[],['expected_version'=>4])['status']===200
    && $call('PRODUCT_READ','GET',$release.'.json')['status']===404,
    'Eligible deletion, stale revision denial or deletion readback failed.');
echo "Product publication, unpublication and eligible deletion readback PASS\n";

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

requireSynthetic($call('SHOP_DISCONNECT','DELETE','/v1/shops/108/connection.json')['status']===404,
    'Cross-shop disconnect was accepted.');
requireSynthetic($call('SHOP_DISCONNECT','DELETE',$root.'/connection.json',['unexpected'=>true])['status']===422,
    'Unexpected disconnect body was accepted.');
requireSynthetic($call('SHOP_DISCONNECT','DELETE',$root.'/connection.json')['status']===200,
    'The scoped synthetic shop could not be disconnected.');
$restored=new SyntheticPrintifyTransport('107');
$restored->restoreFixtureState($transport->exportFixtureState());
requireSynthetic($restored->request(['operation'=>'SHOPS_READ','method'=>'GET','endpoint'=>'/v1/shops.json',
    'provider_shop_id'=>'107','body'=>[]])['body']===[], 'Disconnected shop reappeared after restore.');

echo "Synthetic Printify transport fault and lifecycle fixtures PASS\n";
