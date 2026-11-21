<?php
declare(strict_types=1);

use DigiForgePrintifyApi\Runtime;

$GLOBALS['fixture_caps'] = ['manage_options','digiforge_printify_review_shop_107'];
check(Runtime::runSyntheticJourney()['state'] === 'DENIED', 'Synthetic execution enabled by default');
define('DIGIFORGE_PRINTIFY_SYNTHETIC_JOURNEY', true);
check(Runtime::runSyntheticJourney()['state'] === 'DENIED', 'Nonlocal environment admitted');
$GLOBALS['fixture_environment'] = 'local';
check(in_array('OFFLINE_SYNTHETIC_JOURNEY', Runtime::discover()['capabilities'], true), 'Synthetic journey unavailable');
$result = Runtime::runSyntheticJourney();
check($result['state'] === 'SYNTHETIC_COMPLETE', 'Synthetic journey did not complete: '.json_encode($result));
check(array_column($result['steps'], 'operation') === [
    'SHOPS_READ', 'BLUEPRINT_READ', 'VARIANTS_READ', 'IMAGE_UPLOAD', 'IMAGE_READ', 'PRODUCT_CREATE',
    'PRODUCT_READ', 'ORDER_SHIPPING_QUOTE', 'ORDER_CREATE', 'ORDER_READ', 'ORDER_SEND_TO_PRODUCTION',
    'ORDER_READ', 'SYNTHETIC_RECONCILIATION',
], 'Unexpected scenario path');
check(!$result['provider_execution_authorized'] && !$result['provider_outcome_confirmed'] && !$result['retry_permitted'],
    'Synthetic result granted operational authority');
$GLOBALS['fixture_caps'] = ['manage_options', 'digiforge_printify_review_shop_108'];
check(Runtime::runSyntheticJourney()['state'] === 'DENIED', 'Cross-shop operator admitted');

$transport = new DigiForgePrintifyApi\SyntheticPrintifyTransport('107');
$client = new DigiForgePrintifyApi\SyntheticPrintifyClient('107', $transport);
$upload = ['file_name'=>'fixture.png','contents'=>base64_encode($image)];
$transport->faultNext('timeout');
$timeout = $client->run('IMAGE_UPLOAD', $upload);
check($timeout['state'] === 'UNKNOWN' && !$timeout['retry_permitted'], 'Timeout promoted or retry enabled');
$transport->faultNext('lost_ack');
$lost = $client->run('IMAGE_UPLOAD', $upload);
check($lost['state'] === 'UNKNOWN' && !$lost['retry_permitted'], 'Lost acknowledgment promoted');
$readback = $client->run('IMAGE_READ', ['image_id'=>'image-1']);
check($readback['state'] === 'SYNTHETIC_OBSERVED' && !$readback['provider_outcome_confirmed'],
    'Readback attributed to provider');
check($client->run('IMAGE_UPLOAD', $upload)['status'] === 409, 'Duplicate fixture upload admitted');
$transport->faultNext('rate_limit');
$rate = $client->run('SHOPS_READ');
check($rate['state'] === 'RATE_LIMITED' && !$rate['retry_permitted'], 'Rate limiting not bounded');
check((new DigiForgePrintifyApi\SyntheticPrintifyClient('108', $transport))->run('SHOPS_READ')['status'] === 403,
    'Cross-shop client reached transport state');
$transport->faultNext('validation_error');
check($client->run('IMAGE_UPLOAD', $upload)['status'] === 422, 'Synthetic validation error lost');
$page = $client->run('UPLOADS_READ', ['page'=>1,'limit'=>1]);
check($page['state'] === 'SYNTHETIC_OBSERVED' && array_column($page['data']['data'], 'id') === ['image-1'],
    'Fixture upload pagination failed');
$webhook = $client->run('WEBHOOK_CREATE', [
    'topic'=>'order:created','url'=>DigiForgePrintifyApi\SyntheticPrintifyTransport::WEBHOOK_URLS[0],
]);
check($webhook['state'] === 'SYNTHETIC_OBSERVED', 'Fixture webhook creation failed');
check($client->run('WEBHOOKS_READ')['data']['subscriptions'][0]['id'] === $webhook['data']['id'],
    'Fixture webhook readback mismatch');
check($client->run('WEBHOOK_DELETE',['webhook_id'=>$webhook['data']['id']])['state'] === 'SYNTHETIC_OBSERVED',
    'Fixture webhook deletion failed');
