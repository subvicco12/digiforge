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

function journal(): string
{
    $path = tempnam(sys_get_temp_dir(), 'df-get-');
    file_put_contents($path, '[]');
    register_shutdown_function(static function () use ($path): void {
        @unlink($path);
        @unlink($path.'.lock');
    });
    return $path;
}

$requests = 0;
$held = false;
$keys = sodium_crypto_sign_seed_keypair(str_repeat('a', 32));
$key = sodium_crypto_sign_secretkey($keys);
$scope = ['site'=>'fixture-site','actor'=>1,'session'=>str_repeat('e', 64),'revision'=>str_repeat('a', 64),
    'account'=>'acct','shop'=>'107','integration'=>'fixture','custody'=>'custody-ref:fixture'];
$now = 2000;
$request = ProtectedGet::request($scope, 'SHOPS_READ', []);
$catalogPlan = ProtectedGet::request($scope, 'BLUEPRINTS_READ', []);
foreach ([
    array_replace($catalogPlan, ['method'=>'POST']),
    array_replace($catalogPlan, ['path'=>'/v1/shops.json']),
    array_replace($catalogPlan, ['selection'=>['unexpected'=>true]]),
] as $invalidPlan) {
    try {
        ProtectedGet::project($invalidPlan, '[{"id":1,"title":"Fixture"}]');
        throw new RuntimeException('Noncanonical read request reached response projection.');
    } catch (InvalidArgumentException $expected) {
    }
}
$orderPlan = ProtectedGet::request($scope, 'ORDERS_READ', []);
try {
    ProtectedGet::project($orderPlan, '{"shop_id":108,"data":[],"current_page":1,"last_page":1}');
    throw new RuntimeException('Foreign scoped empty page reached response projection.');
} catch (InvalidArgumentException $expected) {
}
foreach ([
    ['SHOPS_READ',['shop'=>'108']],
    ['ORDER_READ',['order_id'=>'order-1','page'=>1]],
    ['PRODUCTS_READ',['page'=>1,'unexpected'=>'value']],
    ['BLUEPRINTS_READ',['kind'=>'provider']],
] as [$operation,$selection]) {
    try {
        ProtectedGet::request($scope,$operation,$selection);
        throw new RuntimeException('Unexpected selector was admitted');
    } catch (InvalidArgumentException $expected) {
    }
}
check(ProtectedGet::request($scope,'PRODUCTS_READ',['page'=>2,'limit'=>3])['selection']['limit']===3,
    'Explicit bounded API page size was lost');
echo "Shared selector map rejects extraneous fields and preserves bounded API pagination.\n";
$mockups=ProtectedGet::request($scope,'MOCKUP_SELECTION_READ',['product_id'=>'product-1']);
check($mockups['path']==='/v1/shops/107/products/product-1/mockup-selection.json','Incorrect mockup scope');
$mockup=['mockup_id'=>'product-1_front','type'=>'GENERATED','src'=>'https://images.printify.com/mockup/front.jpg?private=discard',
    'variant_ids'=>[9],'selected'=>true];
$projected=ProtectedGet::project($mockups,json_encode([$mockup],JSON_THROW_ON_ERROR));
check($projected===['shop_id'=>'107','product_id'=>'product-1','mockups'=>[array_diff_key($mockup,['src'=>true])]],'Mockup response exposed URL');
foreach ([
    [$mockups,[$mockup,$mockup]],
    [$mockups,[array_replace($mockup,['variant_ids'=>[9,9]])]],
    [$mockups,[array_replace($mockup,['selected'=>'yes'])]],
    [$mockups,[array_replace($mockup,['mockup_id'=>'<script>'])]],
    [$mockups,[array_replace($mockup,['src'=>'https://user@example.invalid/image'])]],
    [$mockups,[array_replace($mockup,['src'=>'file:///etc/passwd'])]],
    [array_replace($mockups,['scope'=>array_replace($scope,['shop'=>'108'])]),[$mockup]],
    [array_replace($mockups,['selection'=>['product_id'=>'foreign']]),[$mockup]],
] as [$wire,$rows]) {
    try {
        ProtectedGet::project($wire,json_encode($rows,JSON_THROW_ON_ERROR));
        throw new RuntimeException('Unsafe mockup projection accepted');
    } catch (InvalidArgumentException $expected) {
    }
}
$variants=ProtectedGet::request($scope,'VARIANTS_READ',['blueprint_id'=>42,'provider_id'=>7,'show-out-of-stock'=>'1']);
check(str_ends_with($variants['path'],'?show-out-of-stock=1'),'Typed availability query missing');
foreach (['2',true,1,'1&shop=108'] as $value) {
    try {
        ProtectedGet::request($scope,'VARIANTS_READ',array_replace($variants['selection'],['show-out-of-stock'=>$value]));
        throw new RuntimeException('Availability injection accepted');
    } catch (InvalidArgumentException $expected) {
    }
}
$orders=ProtectedGet::request($scope,'ORDERS_READ',['page'=>2,'status'=>'fulfilled','sku'=>'SKU-9']);
check(str_ends_with($orders['path'],'?page=2&limit=10&status=fulfilled&sku=SKU-9'),'Order filters missing');
$order=['id'=>'order-1','shop_id'=>107,'status'=>'fulfilled','line_items'=>[['product_id'=>'product-1','variant_id'=>9,'quantity'=>1,'sku'=>'SKU-9']]];
$page=['data'=>[$order],'current_page'=>2,'per_page'=>10,'last_page'=>3];
check(ProtectedGet::project($orders,json_encode($page,JSON_THROW_ON_ERROR))['filters']===['status'=>'fulfilled','sku'=>'SKU-9'],'Pagination lost filters');
foreach ([array_replace($order,['status'=>'on-hold']),array_replace($order,['line_items'=>[]])] as $foreign) {
    try {
        ProtectedGet::project($orders,json_encode(array_replace($page,['data'=>[$foreign]]),JSON_THROW_ON_ERROR));
        throw new RuntimeException('Mismatched order filter accepted');
    } catch (InvalidArgumentException $expected) {
    }
}
echo "Mockup scope, deduplication, unsafe URL, availability allowlist and order filters PASS\n";
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
    }, journal());
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
    }, journal());
check($gate->read($request,$grant,$sign($grant),$now)['state']==='DENIED','External lock ignored');
$policy['external_lock'] = false;
$policy['release_hold'] = true;
check($gate->read($request,$grant,$sign($grant),$now)['state']==='DENIED','Release hold ignored');
$policy['release_hold'] = false;
check($gate->read($request,$grant,$sign($grant),$now)['state']==='DENIED','Post-read revocation ignored');
check($gate->read($request,$grant,$sign($grant),$now)['state']==='DENIED','Revoked admission repeated');
echo "Eleven extracted fixture projections, detail scope, pagination and in-flight revocation passed.\n";

$product = ['id'=>'product-1','shop_id'=>107,'blueprint_id'=>42,'print_provider_id'=>7,
    'variants'=>[['id'=>9,'price'=>1000,'is_enabled'=>true]],'print_areas'=>[]];
$order = ['id'=>'order-1','shop_id'=>107,'external_id'=>'fixture','status'=>'partially-fulfilled',
    'line_items'=>[['product_id'=>'product-1','variant_id'=>9,'quantity'=>2]],
    'address_to'=>['email'=>'never-disclose@example.invalid'],
    'shipments'=>[['carrier'=>'FixtureCarrier','number'=>'fixture-tracking-1','url'=>'https://example.invalid/receipt']]];
$detail = ProtectedGet::request($scope,'PRODUCT_READ',
    ['product_id'=>'product-1','blueprint_id'=>42,'provider_id'=>7,'variant_ids'=>[9]]);
check(ProtectedGet::project($detail,json_encode($product,JSON_THROW_ON_ERROR))['id']==='product-1','Product detail unavailable');
$detail = ProtectedGet::request($scope,'ORDER_READ',['order_id'=>'order-1']);
$projection = ProtectedGet::project($detail,json_encode($order,JSON_THROW_ON_ERROR));
check($projection['status']==='partially-fulfilled'
    && $projection['shipments'][0]['tracking_hash']===hash('sha256','fixture-tracking-1')
    && !str_contains(json_encode($projection,JSON_THROW_ON_ERROR),'example.invalid'),'Order scope, tracking or address leak');
$last = ProtectedGet::request($scope,'ORDERS_READ',['page'=>40,'limit'=>2]);
$projection = ProtectedGet::project($last,json_encode(['data'=>[$order],'current_page'=>40,
    'per_page'=>2,'last_page'=>42,'next_page_url'=>'https://example.invalid'],JSON_THROW_ON_ERROR));
check($projection['next_page']===null && $projection['page_window_exhausted'],'Continuation exceeded page window');

$rateGateway = new FixtureReadGateway(sodium_crypto_sign_publickey($keys),
    static fn(): array => ['revision'=>1,'stop_all'=>false,'external_lock'=>false,'release_hold'=>false,'revoked'=>[]],
    static fn(): array => ['status'=>429,'body'=>'{"sensitive":"do not expose"}'], journal());
for ($offset = 0; $offset < 11; ++$offset) {
    $grant['id'] = sprintf('%032x', $offset);
    $rate = $rateGateway->read($request,$grant,$sign($grant),$now);
    check($rate['state']==='UNAVAILABLE' && $rate['reason']===($offset < 10 ? 'RATE_LIMITED' : 'FIXTURE_QUOTA_EXHAUSTED')
        && !$rate['retry_permitted'] && !isset($rate['data']),'Rate limit, fixture quota or redaction');
}
echo "Product and order detail, partial fulfillment, private tracking projection, rate limit and quota passed.\n";

$journal = journal();
try {
    $grant['id'] = str_repeat('b',32);
    $policy = static fn (): array => ['revision'=>1,'stop_all'=>false,'external_lock'=>false,'release_hold'=>false,'revoked'=>[]];
    $fixtureShop = static fn (): array => ['status'=>200,'body'=>'[{"id":107,"title":"Fixture","sales_channel":"api"}]'];
    $make = static fn () => new FixtureReadGateway(sodium_crypto_sign_publickey($keys),$policy,$fixtureShop,$journal);
    $signed = $make()->exchange($request,$grant,$sign($grant),$now,$key);
    $publicKey = sodium_crypto_sign_publickey($keys);
    check(ProtectedGet::verifyReply($request,$grant['id'],$signed,$publicKey,$now)['state']==='OBSERVED','Signed receipt rejected');
    $replay = $make()->exchange($request,$grant,$sign($grant),$now,$key);
    check(ProtectedGet::verifyReply($request,$grant['id'],$replay,$publicKey,$now)['state']==='DENIED','Replay after reopen admitted');
    $signed['receipt']['data']['shops'][0]['id'] = 108;
    check(ProtectedGet::verifyReply($request,$grant['id'],$signed,$publicKey,$now)['state']==='UNAVAILABLE','Modified response accepted');
    echo "Signed request/response, tamper rejection and durable restart replay passed.\n";
} finally {
    @unlink($journal);
    @unlink($journal.'.lock');
}

if (!function_exists('pcntl_fork')) {
    throw new RuntimeException('pcntl required for concurrent fixture admission');
}
$journal = journal();
$grant['id'] = str_repeat('c',32);
$dir = $journal.'.race';
mkdir($dir,0700);
$children = [];
for ($i=0;$i<2;++$i) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        $limit = microtime(true)+5;
        while (!is_file($dir.'/start') && microtime(true)<$limit) { usleep(1000); }
        $gateway = new FixtureReadGateway($publicKey,$policy,static function () use ($fixtureShop,$dir): array {
            file_put_contents($dir.'/admissions',"1\n",FILE_APPEND|LOCK_EX);
            return $fixtureShop();
        },$journal);
        $result = $gateway->read($request,$grant,$sign($grant),$now);
        file_put_contents($dir.'/'.$i,$result['state']);
        // Avoid fixture shutdown cleanup inherited from the parent.
        posix_kill(getmypid(), SIGKILL);
        exit(1);
    }
    check($pid>0,'Worker start failed');
    $children[] = $pid;
}
touch($dir.'/start');
foreach ($children as $pid) { pcntl_waitpid($pid,$status); }
check(file_get_contents($dir.'/admissions')==="1\n",'Multiple workers admitted');
$results = [file_get_contents($dir.'/0'),file_get_contents($dir.'/1')];
check(count(array_filter($results,static fn(string $state): bool => $state==='OBSERVED'))===1,'Concurrent observations diverged');
foreach (glob($dir.'/*') as $file) { unlink($file); }
rmdir($dir);
unlink($journal);
check((new FixtureReadGateway($publicKey,$policy,$fixtureShop,$journal))->read($request,$grant,$sign($grant),$now)['reason']
    === 'ADMISSION_STORE_UNAVAILABLE','Missing admission state reopened');
echo "Separate workers admitted once, and lost journal state denied.\n";

$variantSelection = ['blueprint_id'=>42,'provider_id'=>7];
$variant = ['id'=>9,'title'=>'Documented fixture',
    'options'=>['color'=>'Heather Grey','size'=>'XS','finish'=>'Matte'],
    'placeholders'=>[['position'=>'front','width'=>1200,'height'=>900]]];
$variantList = ProtectedGet::request($scope,'VARIANTS_READ',$variantSelection);
$variantDetail = ProtectedGet::request($scope,'VARIANT_READ',$variantSelection+['variant_id'=>9]);
$wire = static fn(array $row): string => json_encode(['id'=>42,'variants'=>[$row]],JSON_THROW_ON_ERROR);
check(ProtectedGet::project($variantList,$wire($variant))['variants'][0]['options']===$variant['options'],
    'Variant list dropped supplied options');
$detail = ProtectedGet::project($variantDetail,$wire($variant));
check($detail['options']===$variant['options'] && $detail['print_areas'][0]['width']===1200
    && $detail['print_areas'][0]['bleed']===null,'Detail lost options or invented dimensions');
$missing = $variant;
unset($missing['options']);
check(ProtectedGet::project($variantDetail,$wire($missing))['options']===null,'Missing options were inferred');
foreach ([['red'], ['size'=>9], ['size'=>'<bad>'], ['size'=>''], ['a'=>'1','b'=>'2','c'=>'3','d'=>'4']] as $options) {
    foreach ([$variantList,$variantDetail] as $intent) {
        try {
            ProtectedGet::project($intent,$wire(['options'=>$options]+$variant));
            throw new RuntimeException('Malformed options accepted');
        } catch (InvalidArgumentException) {}
    }
}
echo "Variant list/detail source options: positive, unknown and malformed cases passed.\n";

$productIntent = ProtectedGet::request($scope,'PRODUCT_READ',
    ['product_id'=>'product-1','blueprint_id'=>42,'provider_id'=>7,'variant_ids'=>[9]]);
$product['print_areas'] = [['variant_ids'=>[9],'placeholders'=>[['position'=>'front','images'=>[
    ['id'=>'asset-1','name'=>'Fixture front.png','type'=>'image/png','width'=>1200,'height'=>900,
        'x'=>0.5,'y'=>0.5,'scale'=>1,'angle'=>0,'src'=>'https://private.example.invalid/secret'],
    ['id'=>'asset-2','name'=>'https://private.example.invalid/secret','type'=>'text/html','width'=>'1200','height'=>-1,
        'x'=>0.25,'y'=>0.25,'scale'=>0.5,'angle'=>15],
]]]]];
$product['images'] = [['src'=>'https://images.printify.com/mockup/fixture.jpg','variant_ids'=>[9],'position'=>'front']];
$art = ProtectedGet::project($productIntent,json_encode($product,JSON_THROW_ON_ERROR));
$place = $art['print_areas'][0]['placeholders'][0];
check($art['blueprint_id']===42 && $art['print_provider_id']===7
    && $art['print_areas'][0]['variant_ids']===[9] && $place['position']==='front', 'Lost product placement binding');
check($place['images'][0]['id']==='asset-1' && $place['images'][0]['x']===0.5
    && $place['images'][0]['metadata']===['name'=>'Fixture front.png','type'=>'image/png','width'=>1200,'height'=>900],
    'Lost metadata or assigned it to the wrong placement');
check($place['images'][1]['metadata']===['name'=>null,'type'=>null,'width'=>null,'height'=>null]
    && $art['images'][0]['variant_ids']===[9] && !str_contains(json_encode($art),'private.example.invalid'),
    'Unsafe metadata or artwork URL leaked');
$product['print_provider_id'] = 8;
try {
    ProtectedGet::project($productIntent,json_encode($product,JSON_THROW_ON_ERROR));
    throw new RuntimeException('Product provider mismatch was accepted');
} catch (InvalidArgumentException) {}
echo "Scoped artwork metadata, placement, absent/invalid values, private URL omission and provider binding passed.\n";

$sku = '19779789605456636343';
$skuIntent = ProtectedGet::request($scope,'PRODUCT_SKU_READ',
    ['product_id'=>'product-1','blueprint_id'=>42,'provider_id'=>7,'sku'=>$sku]);
check($skuIntent['method']==='GET' && $skuIntent['path']==='/v1/shops/107/products/product-1.json','SKU route widened');
$product['print_provider_id']=7;
$product['variants'][0]['sku']=$sku;
$product['variants'][]=['id'=>10,'sku'=>'other-sku','price'=>900,'is_enabled'=>true];
$skuResult=ProtectedGet::project($skuIntent,json_encode($product,JSON_THROW_ON_ERROR));
check($skuResult['sku']===$sku && $skuResult['variant_id']===9
    && $skuResult['print_areas'][0]['variant_ids']===[9]
    && !str_contains(json_encode($skuResult),'other-sku'),'Exact SKU resolution or projection failed');
foreach ([
    ['variants'=>[['id'=>9,'sku'=>$sku,'price'=>1000,'is_enabled'=>false]]],
    ['variants'=>[['id'=>9,'sku'=>19,'price'=>1000,'is_enabled'=>true]]],
    ['variants'=>[$product['variants'][0],['id'=>10,'sku'=>$sku,'price'=>900,'is_enabled'=>true]]],
    ['shop_id'=>108], ['print_provider_id'=>8], ['id'=>'another-product'],
] as $bad) {
    try {
        ProtectedGet::project($skuIntent,json_encode($bad+$product,JSON_THROW_ON_ERROR));
        throw new RuntimeException('Unsafe SKU evidence accepted');
    } catch (InvalidArgumentException) {}
}
$guide = ProtectedGet::request($scope,'BLUEPRINT_SIZE_GUIDE_READ',['blueprint_id'=>42]);
check($guide['path']==='/v1/catalog/blueprints/42/size_guide.json','Wrong size guide route');
$guideBody=['sizes'=>['XS'],'types'=>[['name'=>'Width','units'=>'cm','ranges'=>[['from'=>'40','to'=>'41']]]]];
check(ProtectedGet::project($guide,json_encode($guideBody,JSON_THROW_ON_ERROR))===['blueprint_id'=>42]+$guideBody,
    'Source size guide units were changed');
try {
    ProtectedGet::project($guide,'{"sizes":["XS"],"types":[{"name":"Width","units":"cm","ranges":[]}]}');
    throw new RuntimeException('Misaligned size guide passed');
} catch (InvalidArgumentException) {}
$variant['placeholders'][0]['decoration_method']='dtf';
$decoration=ProtectedGet::project($variantDetail,$wire($variant))['print_areas'][0];
check($decoration['decoration_method']==='dtf' && $decoration['width']===1200
    && $decoration['bleed']===null && $decoration['safe_zone']===null,'Decoration or UNKNOWN geometry drifted');
unset($variant['placeholders'][0]['decoration_method']);
check(ProtectedGet::project($variantDetail,$wire($variant))['print_areas'][0]['decoration_method']===null,
    'Absent decoration became known');

$provider = ProtectedGet::request($scope,'BLUEPRINT_PROVIDER_READ',['blueprint_id'=>42,'provider_id'=>7]);
$providerBody=['id'=>7,'title'=>'Fixture provider','decoration_methods'=>['dtf'],'production_time'=>null];
$providerResult=ProtectedGet::project($provider,json_encode($providerBody,JSON_THROW_ON_ERROR));
check($providerResult['id']===7 && $providerResult['blueprint_id']===42
    && $providerResult['decoration_methods']===['dtf'] && $providerResult['production_time']===null,
    'Provider and blueprint binding missing');
echo "Exact SKU, ambiguity and scope denial, decoration, size guide and provider detail passed.\n";

$artIntent = ProtectedGet::request($scope,'PRODUCT_ARTWORK_READ',
    ['product_id'=>'product-1','blueprint_id'=>42,'provider_id'=>7,'variant_ids'=>[9,10],'image_id'=>'asset-1']);
check($artIntent['path']==='/v1/shops/107/products/product-1.json','Artwork unexpectedly used an account lookup');
$linked=ProtectedGet::project($artIntent,json_encode($product,JSON_THROW_ON_ERROR));
check($linked['product_id']==='product-1' && $linked['placements'][0]['variant_ids']===[9]
    && $linked['placements'][0]['artwork']['metadata']['width']===1200
    && !str_contains(json_encode($linked),'asset-2') && !str_contains(json_encode($linked),'private.example.invalid'),
    'Unrelated or private artwork data escaped the projection');
foreach ([['print_areas'=>[]], ['shop_id'=>108], ['id'=>'foreign-product'], ['print_provider_id'=>8]] as $bad) {
    try {
        ProtectedGet::project($artIntent,json_encode($bad+$product,JSON_THROW_ON_ERROR));
        throw new RuntimeException('Missing or substituted artwork ownership accepted');
    } catch (InvalidArgumentException) {}
}
echo "Product-linked artwork ownership, fixed route, scope denial and projection passed.\n";
