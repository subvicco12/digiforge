<?php
declare(strict_types=1);

use DigiForgePrintifyApi\SyntheticPrintifyClient;
use DigiForgePrintifyApi\SyntheticPrintifyTransport;
use DigiForgePrintifyApi\Engine\POD\PrintifyApiReadEvidence;

define('ABSPATH', __DIR__.'/fixture-wordpress/');
$plugin = $argv[1] ?? __DIR__.'/../digiforge-printify-api';
require_once $plugin.'/SyntheticPrintifyTransport.php';
require_once $plugin.'/SyntheticPrintifyClient.php';
if (!SyntheticPrintifyClient::available()) {
    throw new RuntimeException('Extracted standalone package required');
}
$transport = new SyntheticPrintifyTransport('107');
$client = new SyntheticPrintifyClient('107', $transport);
$selectors = $client::operations();
if (count(array_unique($selectors)) !== 51) {
    throw new RuntimeException('Reviewed selector inventory changed');
}
foreach ($selectors as $selector) {
    $result = $client->run($selector, ['unexpected_payload_field'=>['invalid']]);
    if ($result['reason'] !== 'REQUEST_VALIDATION' || $result['state'] !== 'DENIED'
        || $result['provider_execution_authorized'] || $result['retry_permitted']
        || $transport->admittedRequests() !== []) {
        throw new RuntimeException('Malformed input admitted for '.$selector);
    }
}
$valid = [
    'id'=>'fixture-order','shop_id'=>107,'status'=>'fulfilled','line_items'=>[
        ['id'=>'line-1','product_id'=>'product-1','variant_id'=>9,'quantity'=>1],
        ['id'=>'line-2','product_id'=>'product-2','variant_id'=>10,'quantity'=>2],
    ],
];
$first = ['carrier'=>'Synthetic carrier','number'=>'fixture-1','url'=>'https://tracking.example.invalid/one'];
$second = ['carrier'=>'Synthetic carrier','number'=>'fixture-2','url'=>'https://tracking.example.invalid/two',
    'delivered_at'=>'2026-11-19T00:00:00Z'];
$result = PrintifyApiReadEvidence::project('ORDER_READ', $valid+['shipments'=>[$first,$second]], '107', ['order_id'=>'fixture-order']);
if (array_column($result['shipments'], 'tracking_hash') !== [hash('sha256','fixture-1'),hash('sha256','fixture-2')]
    || $result['shipments'][0]['delivered_at'] !== null || $result['shipments'][1]['delivered_at'] !== $second['delivered_at']) {
    throw new RuntimeException('Multiple tracking identities or observation times were lost');
}
foreach ([
    ['ORDER_READ', ['id'=>'wrong-order']+$valid, ['order_id'=>'fixture-order']],
    ['ORDER_READ', $valid+['shipments'=>[$first,$first]], ['order_id'=>'fixture-order']],
    ['ORDER_READ', $valid+['shipments'=>[array_diff_key($first,['number'=>true])]], ['order_id'=>'fixture-order']],
    ['ORDER_READ', $valid+['shipments'=>[['delivered_at'=>'2026-02-30T00:00:00Z']+$first]], ['order_id'=>'fixture-order']],
    ['ORDERS_READ', ['data'=>[$valid,$valid],'current_page'=>1], []],
    ['ORDERS_READ', ['data'=>[$valid],'current_page'=>2,'per_page'=>10], []],
    ['ORDERS_READ', ['data'=>[$valid],'current_page'=>1,'last_page'=>0], []],
] as [$selector,$body,$input]) {
    try {
        PrintifyApiReadEvidence::project($selector, $body, '107', $input);
    } catch (InvalidArgumentException) {
        continue;
    }
    throw new RuntimeException('Incompatible result admitted for '.$selector.' '.json_encode($body));
}
echo "Payload qualification PASS: 51 malformed selector requests, seven order response negatives, two tracking identities\n";
