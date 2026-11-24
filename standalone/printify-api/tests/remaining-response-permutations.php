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
$client = new SyntheticPrintifyClient('107', $transport);
$catalog = ['blueprint_id'=>42,'provider_id'=>7];
$legacy = $client->run('CATALOG_SHIPPING_V1_READ',$catalog);
if ($legacy['state'] !== 'SYNTHETIC_OBSERVED' || $legacy['data']['quote_is_guarantee']) {
    throw new RuntimeException('Legacy result unavailable or promoted to quotation');
}
$project = new ReflectionMethod($client,'project');
$profile = $legacy['data']['profiles'][0];
$expanded = $legacy['data'];
unset($expanded['quote_is_guarantee']);
$expanded['profiles'] = [['variant_ids'=>[9,10],'countries'=>['US']]+$profile, ['variant_ids'=>[10],'countries'=>['CA']]+$profile];
$result = $project->invoke($client,'CATALOG_SHIPPING_V1_READ',$catalog,[],$expanded,200);
if (array_column($result['profiles'],'countries') !== [['US'],['CA']] || $result['quote_is_guarantee']) {
    throw new RuntimeException('Disjoint coverage lost or treated as a quote');
}
$expanded['profiles'][1]['countries'][] = 'US';
try {
    $project->invoke($client,'CATALOG_SHIPPING_V1_READ',$catalog,[],$expanded,200);
    throw new RuntimeException('Duplicate variant-country rate admitted');
} catch (InvalidArgumentException) {
    // The overlapping profile is ambiguous even when amounts agree.
}
foreach (['PRODUCTS_READ','ORDERS_READ','UPLOADS_READ'] as $selector) {
    $input = ['page'=>2147483647,'limit'=>10];
    $result = $client->run($selector,$input);
    if ($result['state'] !== 'SYNTHETIC_OBSERVED' || $result['data']['data'] !== [] || $result['data']['next_page'] !== null
        || $result['data']['pagination_followed'] || $client->run($selector,['page'=>2147483648]+$input)['reason'] !== 'REQUEST_VALIDATION') {
        throw new RuntimeException('Invalid bounded continuation for '.$selector);
    }
    $transport->faultNext('rate_limit');
    $limited = $client->run($selector);
    if ($limited['state'] !== 'RATE_LIMITED' || $limited['bounded_retry_after'] !== 1 || $limited['retry_permitted']) {
        throw new RuntimeException('Fixture rate limit incorrectly opened retry for '.$selector);
    }
}
$other = new SyntheticPrintifyClient('108', new SyntheticPrintifyTransport('108'));
if ($other->run('SHOPS_READ')['data']['shops'][0]['id'] !== 108
    || PrintifyApiOutcome::classify(429,['Retry-After'=>'99999'],3,'GET')['bounded_retry_after'] !== 3600) {
    throw new RuntimeException('Independent scope or quota hint bound lost');
}
echo "Shipping/pagination permutations PASS: disjoint and overlapping coverage, three bounded list responses, typed rate limits and other scope\n";
