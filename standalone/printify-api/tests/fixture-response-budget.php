<?php
declare(strict_types=1);

use DigiForgePrintifyApi\SyntheticPrintifyClient;
use DigiForgePrintifyApi\SyntheticPrintifyTransport;

define('ABSPATH', __DIR__.'/fixture-wordpress/');
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
$plugin = $argv[1];
require $plugin.'/SyntheticPrintifyTransport.php';
require $plugin.'/SyntheticPrintifyClient.php';
$transport = new SyntheticPrintifyTransport('107');
$client = new SyntheticPrintifyClient('107', $transport);
for ($i=0; $i<100; ++$i) {
    $result = $client->run('ORDERS_READ', ['page'=>1,'limit'=>1]);
    if ($result['state'] !== 'SYNTHETIC_OBSERVED' || $result['data']['next_page'] !== null
        || $result['data']['pagination_followed']) {
        throw new RuntimeException('Valid terminal page unavailable within budget');
    }
}
foreach (['ORDERS_READ','SHOP_DISCONNECT','ORDERS_READ'] as $selector) {
    $result = $client->run($selector);
    if ($result['status'] !== 429 || $result['retry_permitted'] || $result['provider_execution_authorized']
        || count($transport->admittedRequests()) !== 100) {
        throw new RuntimeException('Nominal request admitted beyond fixture budget');
    }
}
echo "Fixture page and budget boundary PASS (100 terminal reads, three blocked at the limit)\n";
