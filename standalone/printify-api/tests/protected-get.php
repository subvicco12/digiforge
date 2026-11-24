<?php
declare(strict_types=1);
define('ABSPATH', '/disposable/');
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
require $argv[1].'/ProtectedGet.php';
require __DIR__.'/../gateway/FixtureReadGateway.php';

use DigiForgePrintifyApi\ProtectedGet;
use DigiForgePrintifyApi\Gateway\FixtureReadGateway;

function check(bool $result, string $message): void
{
    if (!$result) { throw new RuntimeException($message); }
}

$requests = 0;
$held = false;
$keys = sodium_crypto_sign_seed_keypair(str_repeat('a', 32));
$key = sodium_crypto_sign_secretkey($keys);
$scope = ['site'=>'fixture-site','actor'=>1,'session'=>str_repeat('e', 64),'revision'=>str_repeat('a', 64),
    'account'=>'acct','shop'=>'107','integration'=>'fixture','custody'=>'custody-ref:fixture'];
$now = 2000;
$request = ProtectedGet::request($scope, 'SHOPS_READ', []);
$grant = ['issuer'=>'fixture-custodian','audience'=>ProtectedGet::VERSION,'request_digest'=>ProtectedGet::digest($request),
    'issued_at'=>$now,'expires_at'=>$now+30,'policy_revision'=>1,'id'=>str_repeat('4', 32)];
$sign = static fn(array $data): string => bin2hex(sodium_crypto_sign_detached(ProtectedGet::digest($data), $key));
$gateway = new FixtureReadGateway(sodium_crypto_sign_publickey($keys),
    static function () use (&$held): array {
        return ['revision'=>1,'stop_all'=>$held,'external_lock'=>false,'release_hold'=>false,'revoked'=>[]];
    },
    static function (array $scope, string $method, string $path) use (&$requests): array {
        ++$requests;
        check($scope['shop']==='107' && $method==='GET' && $path==='/v1/shops.json', 'Unexpected provider intent');
        return ['status'=>200,'body'=>'[{"id":107,"title":"Synthetic","sales_channel":"fixture"},{"id":108,"title":"private","sales_channel":"fixture"}]'];
    });
check(ProtectedGet::denied()['state']==='BLOCKED', 'Default hold missing');
$bad = $request;
$bad['scope']['shop'] = '108';
check($gateway->read($bad,$grant,$sign($grant),$now)['state']==='DENIED', 'Substitution accepted');
check($gateway->read($request,$grant,$sign($grant),$now+30)['state']==='DENIED', 'Expiry accepted');
check($gateway->read($request,$grant,str_repeat('0',128),$now)['state']==='DENIED', 'Forged grant accepted');
$held = true;
check($gateway->read($request,$grant,$sign($grant),$now)['state']==='DENIED', 'STOP ignored');
$held = false;
$result = $gateway->read($request,$grant,$sign($grant),$now);
check($result['state']==='OBSERVED' && count($result['data']['shops'])===1 && $result['source']==='SYNTHETIC_PRINTIFY', 'Scoped observation missing');
check($gateway->read($request,$grant,$sign($grant),$now)['state']==='DENIED' && $requests===1, 'Replay reached fixture');
foreach (['ORDER_CREATE','PRODUCT_PUBLISH','GET https://example.invalid'] as $operation) {
    try { ProtectedGet::request($scope,$operation,[]); throw new RuntimeException('Write accepted'); }
    catch (InvalidArgumentException) {}
}
echo "Protected GET grant, scope, expiry, signature, STOP, redaction, replay and method tests passed.\n";

$plugin = $argv[1];
require $plugin.'/SyntheticPrintifyTransport.php';
$fixture = new DigiForgePrintifyApi\SyntheticPrintifyTransport('107');
$cases = [
    ['BLUEPRINTS_READ', []],
    ['BLUEPRINT_READ', ['blueprint_id'=>42]],
    ['BLUEPRINT_PROVIDERS_READ', ['blueprint_id'=>42]],
    ['PROVIDERS_READ', []],
    ['PROVIDER_READ', ['provider_id'=>7]],
    ['VARIANTS_READ', ['blueprint_id'=>42,'provider_id'=>7]],
    ['VARIANT_READ', ['blueprint_id'=>42,'provider_id'=>7,'variant_id'=>9]],
    ['CATALOG_SHIPPING_READ', ['blueprint_id'=>42,'provider_id'=>7]],
    ['CATALOG_SHIPPING_METHOD_READ', ['blueprint_id'=>42,'provider_id'=>7,'method'=>'standard']],
    ['PRODUCTS_READ', ['page'=>1,'limit'=>2]],
    ['ORDERS_READ', ['page'=>1,'limit'=>2]],
];
foreach ($cases as [$operation,$selection]) {
    $planned = ProtectedGet::request($scope,$operation,$selection);
    $raw = $fixture->request(['method'=>'GET','operation'=>$operation,'endpoint'=>$planned['path'],'body'=>[],'provider_shop_id'=>'107']);
    check($raw['status']===200, 'Missing fixture for '.$operation);
    $projection = ProtectedGet::project($planned, json_encode($raw['body'], JSON_THROW_ON_ERROR));
    if ($operation === 'VARIANT_READ') {
        check($projection['print_areas'][0]['width']===1200 && $projection['print_areas'][0]['bleed']===null, 'Geometry provenance');
    }
    if ($operation === 'CATALOG_SHIPPING_METHOD_READ') {
        check(!$projection['address_quote'] && $projection['rates']!==[], 'Catalog-rate projection');
    }
}
foreach ([['ORDER_READ',['order_id'=>'order-1']], ['PRODUCT_READ',['product_id'=>'product-1','blueprint_id'=>42,'provider_id'=>7,'variant_ids'=>[9]]]] as [$operation,$selection]) {
    $planned = ProtectedGet::request($scope,$operation,$selection);
    check(str_contains($planned['path'],'/shops/107/'), 'Wrong detail routing');
    try { ProtectedGet::project($planned,'{"id":"different","shop_id":108}'); throw new RuntimeException('Foreign response exposed'); }
    catch (InvalidArgumentException) {}
}
foreach ([0,41,'1'] as $page) {
    try { ProtectedGet::request($scope,'ORDERS_READ',['page'=>$page]); throw new RuntimeException('Invalid page accepted'); }
    catch (InvalidArgumentException) {}
}
$grant['id'] = str_repeat('5',32);
$policy = ['revision'=>1,'stop_all'=>false,'external_lock'=>true,'release_hold'=>false,'revoked'=>[]];
$gate = new FixtureReadGateway(sodium_crypto_sign_publickey($keys),
    static function () use (&$policy): array {return $policy;},
    static function () use (&$policy): array {
        $policy['revoked'][] = str_repeat('5',32);
        return ['status'=>200,'body'=>'[]'];
    });
check($gate->read($request,$grant,$sign($grant),$now)['state']==='DENIED','External lock ignored');
$policy['external_lock'] = false;
$policy['release_hold'] = true;
check($gate->read($request,$grant,$sign($grant),$now)['state']==='DENIED','Release hold ignored');
$policy['release_hold'] = false;
check($gate->read($request,$grant,$sign($grant),$now)['state']==='DENIED','Post-read revocation ignored');
check($gate->read($request,$grant,$sign($grant),$now)['state']==='DENIED','Revoked admission repeated');
echo "Eleven extracted fixture projections, detail scope, pagination and in-flight revocation passed.\n";
