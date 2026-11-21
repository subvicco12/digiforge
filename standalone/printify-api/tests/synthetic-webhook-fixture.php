<?php
declare(strict_types=1);

use DigiForgePrintifyApi\SyntheticWebhookReceiver;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__.'/');
}
require_once ($argv[1] ?? __DIR__.'/../digiforge-printify-api').'/SyntheticWebhookReceiver.php';

function assertSyntheticWebhook(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$id = '55a31a12-f177-4da7-bc35-d13cc67ed072';
$event = ['id'=>$id, 'type'=>'order:created', 'created_at'=>'2026-11-19 00:00:00+00:00',
    'resource'=>['id'=>'order-1','type'=>'order','data'=>['shop_id'=>107]]];
$delivery = SyntheticWebhookReceiver::delivery($event);
$accepted = SyntheticWebhookReceiver::receive('107', $delivery);
assertSyntheticWebhook($accepted['status'] === 200
    && $accepted['state'] === 'SYNTHETIC_OBSERVED'
    && $accepted['event_id'] === $id && $accepted['resource_id'] === 'order-1'
    && $accepted['event_digest'] === hash('sha256',$delivery['body'])
    && $accepted['synthetic'] === true && $accepted['retry_permitted'] === false
    && $accepted['fixture_signature_verified'] === true && $accepted['event_authenticity_verified'] === false
    && $accepted['business_transition_authorized'] === false
    && $accepted['provider_execution_authorized'] === false, 'A fixture was unbound or carried authority.');
$seen = [$id=>$accepted['event_digest']];
assertSyntheticWebhook(SyntheticWebhookReceiver::receive('107',$delivery,$seen)['reason']==='DUPLICATE_EVENT',
    'Identical event was acknowledged a second time.');
$altered = $event;
$altered['resource']['id'] = 'order-2';
assertSyntheticWebhook(SyntheticWebhookReceiver::receive('107',SyntheticWebhookReceiver::delivery($altered),$seen)['reason']==='EVENT_ID_CONFLICT',
    'Changed envelope with the same event ID did not conflict.');
assertSyntheticWebhook(SyntheticWebhookReceiver::receive('107',SyntheticWebhookReceiver::deliveryRaw(' '.$delivery['body']),$seen)['reason']==='EVENT_ID_CONFLICT',
    'Replay comparison accepted changed delivery bytes.');
assertSyntheticWebhook(SyntheticWebhookReceiver::receive('107',$delivery,[$id=>null])['reason']==='EVENT_REPLAY_UNVERIFIED',
    'Legacy state without an exact digest was silently re-accepted.');
assertSyntheticWebhook(SyntheticWebhookReceiver::receive('108',$delivery)['reason']==='EVENT_CONTRACT_OR_SCOPE',
    'Cross-shop evidence was accepted.');
foreach ([
    ['path'=>'https://api.printify.com/synthetic/printify/webhook','reason'=>'EVENT_ROUTE_UNAVAILABLE'],
    ['method'=>'GET','reason'=>'EVENT_ROUTE_UNAVAILABLE'],
    ['headers'=>['content-type'=>'application/json','authentication'=>'fixture'],'reason'=>'EVENT_MEDIA_TYPE'],
    ['body'=>str_repeat(' ',16385),'reason'=>'EVENT_SIZE'],
    ['body'=>' '.$delivery['body'],'reason'=>'EVENT_FIXTURE_SIGNATURE'],
    ['headers'=>['content-type'=>'application/json','x-pfy-signature'=>'sha256='.str_repeat('0',64)],'reason'=>'EVENT_FIXTURE_SIGNATURE'],
] as $failure) {
    $reason=$failure['reason'];
    unset($failure['reason']);
    assertSyntheticWebhook(SyntheticWebhookReceiver::receive('107',array_replace($delivery,$failure))['reason']===$reason,
        'Malformed or unsupported delivery escaped: '.$reason);
}
assertSyntheticWebhook(SyntheticWebhookReceiver::receive('107',SyntheticWebhookReceiver::deliveryRaw('{"id": '))['reason']==='EVENT_MALFORMED',
    'A well-signed malformed fixture escaped.');
assertSyntheticWebhook(SyntheticWebhookReceiver::receive('107',SyntheticWebhookReceiver::deliveryRaw('{"id":"'.$id.'",'.substr($delivery['body'],1)))
    ['reason']==='EVENT_CONTRACT_OR_SCOPE','A duplicate JSON member escaped.');
foreach (['2026-02-31 00:00:00+00:00', "2026-11-19\0"."00:00:00+00:00", '2026-11-19 00:00:00+05:60'] as $timestamp) {
    $bad = SyntheticWebhookReceiver::delivery(array_replace($event,['created_at'=>$timestamp]));
    assertSyntheticWebhook(SyntheticWebhookReceiver::receive('107',$bad)['reason']==='EVENT_TIMESTAMP',
        'Invalid timestamp escaped.');
}
foreach ([[107.0], ['107'], [107,'raw_email'=>'fixture@example.invalid']] as $replacement) {
    $bad=$event;
    $bad['resource']['data']=array_key_exists('raw_email',$replacement)
        ? ['shop_id'=>107,'raw_email'=>$replacement['raw_email']] : ['shop_id'=>$replacement[0]];
    $result=SyntheticWebhookReceiver::receive('107',SyntheticWebhookReceiver::deliveryRaw(json_encode($bad,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)));
    assertSyntheticWebhook($result['reason']==='EVENT_CONTRACT_OR_SCOPE'
        && !str_contains(json_encode($result),'fixture@example.invalid'), 'Unsupported fields or numeric types were accepted.');
}
$bad=$event;
$bad['type']='order:shipped';
assertSyntheticWebhook(SyntheticWebhookReceiver::receive('107',SyntheticWebhookReceiver::deliveryRaw(json_encode($bad,JSON_THROW_ON_ERROR)))
    ['reason']==='EVENT_CONTRACT_OR_SCOPE','Undocumented event type was accepted.');

foreach (['product:created'=>'product','product:updated'=>'product','order:updated'=>'order','order:sent-to-production'=>'order'] as $topic=>$type) {
    $eventForTopic=$event;
    $eventForTopic['type']=$topic;
    $eventForTopic['resource']['type']=$type;
    if ($topic==='order:updated') {
        $eventForTopic['resource']['data']['status']='in-production';
    }
    $observed=SyntheticWebhookReceiver::receive('107',SyntheticWebhookReceiver::delivery($eventForTopic));
    assertSyntheticWebhook($observed['status']===200 && $observed['type']===$topic && $observed['resource_type']===$type,
        'Documented topic envelope failed.');
    if ($topic==='order:updated') {
        assertSyntheticWebhook($observed['resource_status']==='in-production','Documented order status was not projected.');
        $eventForTopic['resource']['data']['status']='invalid-state';
        assertSyntheticWebhook(SyntheticWebhookReceiver::receive('107',SyntheticWebhookReceiver::deliveryRaw(
            json_encode($eventForTopic,JSON_THROW_ON_ERROR)))['reason']==='EVENT_CONTRACT_OR_SCOPE','Unknown order status admitted.');
    }
}

$publish=$event;
$publish['type']='product:publish:started';
$publish['resource']=['id'=>'product-1','type'=>'product','data'=>[
    'shop_id'=>107,'publish_details'=>['title'=>true,'variants'=>false,'description'=>true,'tags'=>true,
        'images'=>false,'key_features'=>false,'shipping_template'=>true],
    'action'=>'create','out_of_stock_publishing'=>0,
]];
$publishResult=SyntheticWebhookReceiver::receive('107',SyntheticWebhookReceiver::delivery($publish));
assertSyntheticWebhook($publishResult['status']===200 && $publishResult['publish_action']==='create'
    && $publishResult['out_of_stock_publishing']===0 && $publishResult['business_transition_authorized']===false,
    'Documented publication event did not keep its fixture-only designation.');
$publish['resource']['data']['publish_details']['title']=1;
assertSyntheticWebhook(SyntheticWebhookReceiver::receive('107',SyntheticWebhookReceiver::deliveryRaw(
    json_encode($publish,JSON_THROW_ON_ERROR)))['reason']==='EVENT_CONTRACT_OR_SCOPE',
    'A nonboolean publication selection was accepted.');

$trackingNumber='TRACK-12345';
$trackingUrl='https://tracking.example.invalid/fixture';
foreach (['order:shipment:created'=>'shipped_at','order:shipment:delivered'=>'delivered_at'] as $topic=>$dateKey) {
    $shipment=$event;
    $shipment['type']=$topic;
    $shipment['resource']['data']+=[$dateKey=>'2026-11-19 03:30:00+03:30','carrier'=>[
        'code'=>'USPS','tracking_number'=>$trackingNumber,'tracking_url'=>$trackingUrl,
    ],'skus'=>['sku-1']];
    if ($dateKey==='shipped_at') {
        $shipment['resource']['data']['line_item_ids']=['line-1'];
    }
    $signedShipment=SyntheticWebhookReceiver::delivery($shipment);
    $shipmentResult=SyntheticWebhookReceiver::receive('107',$signedShipment);
    assertSyntheticWebhook($shipmentResult['status']===200
        && $shipmentResult['tracking_sha256']===hash('sha256',$trackingNumber)
        && $shipmentResult['shipment_at']==='2026-11-19T00:00:00Z'
        && ($shipmentResult['line_item_ids']??null)===($dateKey==='shipped_at'?['line-1']:null)
        && !str_contains(json_encode($shipmentResult),$trackingNumber)
        && !str_contains(json_encode($shipmentResult),$trackingUrl),'Shipment tracking was unbound or disclosed.');
    $duplicated=str_replace('"tracking_number":','"tracking_number":"ignored","tracking_number":',$signedShipment['body']);
    assertSyntheticWebhook(SyntheticWebhookReceiver::receive('107',SyntheticWebhookReceiver::deliveryRaw($duplicated))
        ['reason']==='EVENT_CONTRACT_OR_SCOPE','Duplicate nested carrier member was accepted.');
    $shipment['resource']['data']['carrier']['tracking_url']='file:///tmp/fixture';
    assertSyntheticWebhook(SyntheticWebhookReceiver::receive('107',SyntheticWebhookReceiver::deliveryRaw(
        json_encode($shipment,JSON_THROW_ON_ERROR)))['reason']==='EVENT_CONTRACT_OR_SCOPE',
        'Non-HTTPS tracking location was accepted.');
}

$httpEvent=['id'=>'653b6be8-2ff7-4ab5-a7a6-6889a8b3abcd','type'=>'shop:disconnected',
    'created_at'=>'2026-11-19 00:00:00+00:00','resource'=>['id'=>107,'type'=>'shop','data'=>null]];
$http=SyntheticWebhookReceiver::wire(SyntheticWebhookReceiver::delivery($httpEvent));
[$sender,$receiver]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
fwrite($sender,$http);
stream_socket_shutdown($sender,STREAM_SHUT_WR);
$wire=stream_get_contents($receiver,2048);
fclose($sender);
fclose($receiver);
$observed=SyntheticWebhookReceiver::receiveWire('107',$wire);
if ($observed['status']!==200
    || SyntheticWebhookReceiver::receiveWire('108',$wire)['status']!==422
    || SyntheticWebhookReceiver::receiveWire('107',$wire,[$observed['event_id']=>$observed['event_digest']])['status']!==409
    || SyntheticWebhookReceiver::receiveWire('107',str_replace('sha256=','sha256= ', $wire))['status']!==401
    || SyntheticWebhookReceiver::receiveWire('107',$wire."x")['status']!==400) {
    throw new RuntimeException('HTTP fixture framing, signature, scope or replay validation failed.');
}

echo "Synthetic webhook receiver fixtures PASS\n";
