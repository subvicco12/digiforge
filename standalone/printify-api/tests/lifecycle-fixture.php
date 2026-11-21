<?php
declare(strict_types=1);

use DigiForgePrintifyApi\Runtime;

$chunk = static fn(string $name, string $data): string =>
    pack('N', strlen($data)) . $name . $data . hash('crc32b', $name . $data, true);
$image = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0))
    . $chunk('IDAT', gzcompress("\0\xff\0\0")) . $chunk('IEND', '');
$op = hash('sha256', 'fixture operation');
$approval = hash('sha256', 'fixture approval');
$intent = ['operation_id'=>$op,'approval_sha256'=>$approval,'version'=>3,
    'account_reference'=>'fixture-account','product_id'=>'fixture-product','order_id'=>'fixture-order'];
$line = ['product_id'=>'fixture-product','variant_id'=>17,'quantity'=>1];
$fixture = [
    'scope'=>['shop'=>'107','account_reference'=>'fixture-account','version'=>3,
        'operation_id'=>$op,'approval_sha256'=>$approval],
    'catalog_body'=>'{"variants":[{"id":17}]}',
    'product'=>['title'=>'Synthetic product','description'=>'Fixture only','blueprint_id'=>42,'print_provider_id'=>2,
        'variants'=>[['id'=>17,'price'=>1000,'is_enabled'=>true]],
        'print_areas'=>[['variant_ids'=>[17],'placeholders'=>[['position'=>'front',
            'images'=>[['id'=>'fixture-image','x'=>0.5,'y'=>0.5,'scale'=>1,'angle'=>0]]]]]]],
    'artwork'=>['file_name'=>'fixture.png','contents'=>base64_encode($image)],
    'artwork_reference'=>'fixture-image',
    'order'=>['external_id'=>$op,'line_items'=>[$line],'shipping_method'=>1,
        'is_printify_express'=>false,'is_economy_shipping'=>false,'send_shipping_notification'=>false,
        'address_to'=>['first_name'=>'Fixture','last_name'=>'Only','email'=>'fixture@example.test',
            'phone'=>'','country'=>'US','region'=>'CA','address1'=>'Synthetic street',
            'address2'=>'','city'=>'Fixture','zip'=>'90001']],
    'status'=>408,
    'readback'=>['id'=>'fixture-order','external_id'=>$op,'shop_id'=>'107','status'=>'fulfilled','line_items'=>[$line],
        'shipments'=>[['carrier'=>'Fixture carrier','number'=>'fixture-tracking','url'=>'https://example.test/tracking']]],
];
$result = Runtime::reviewLifecycle('107', $intent, $fixture);
check($result['state'] === 'OFFLINE_REVIEWED', 'Lifecycle preparation failed');
check($result['synthetic_outcome'] === 'UNKNOWN' && $result['retry_permitted'] === false, 'Timeout replay');
check($result['tracking_readback']['tracking_hashes'] === [hash('sha256', 'fixture-tracking')], 'Tracking projection');
check($result['provider_outcome_confirmed'] === false, 'Synthetic readback cleared uncertainty');
check(!str_contains(json_encode($result), 'fixture@example.test'), 'Private order fields disclosed');
foreach ([
    ['scope'=>array_replace($fixture['scope'], ['version'=>2])],
    ['scope'=>array_replace($fixture['scope'], ['account_reference'=>'other-account'])],
    ['artwork'=>['file_name'=>'fixture.png','contents'=>base64_encode('bad')]],
    ['catalog_body'=>'{"variants":[{"id":18}]}'],
    ['readback'=>array_replace($fixture['readback'], ['shop_id'=>'108'])],
    ['readback'=>array_replace($fixture['readback'], ['external_id'=>'unrelated'])],
    ['readback'=>array_replace($fixture['readback'], ['line_items'=>[array_replace($line,['quantity'=>2])]])],
] as $change) {
    check(Runtime::reviewLifecycle('107', $intent, array_replace($fixture, $change))['state'] === 'DENIED', 'Invalid lifecycle accepted');
}
check(Runtime::reviewLifecycle('108', $intent, $fixture)['state'] === 'DENIED', 'Cross-shop lifecycle accepted');
$GLOBALS['fixture_caps'] = [];
check(Runtime::reviewLifecycle('107', $intent, $fixture)['state'] === 'DENIED', 'Anonymous lifecycle accepted');
