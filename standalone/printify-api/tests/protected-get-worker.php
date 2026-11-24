<?php
declare(strict_types=1);

use DigiForgePrintifyApi\Gateway\FixtureReadGateway;
use DigiForgePrintifyApi\ProtectedGet;

define('ABSPATH', '/disposable/');
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
require $argv[1].'/ProtectedGet.php';
require $argv[1].'/SyntheticPrintifyTransport.php';
require __DIR__.'/../gateway/FixtureReadGateway.php';
$input = json_decode(stream_get_contents(STDIN, 32768),true,24,JSON_THROW_ON_ERROR);
$request = $input['request'];
$now = time();
$scope = $request['scope'];
if ($scope['shop'] !== '107' || $scope['account'] !== 'fixture-account' || $scope['actor'] !== 1
    || $scope['integration'] !== 'fixture-read' || $scope['custody'] !== 'custody-ref:fixture') {
    throw new RuntimeException('Fixture scope denied');
}
$grantKeys = sodium_crypto_sign_seed_keypair(str_repeat('a',32));
$replyKeys = sodium_crypto_sign_seed_keypair(str_repeat('b',32));
$grant = ['issuer'=>'fixture-custodian','audience'=>ProtectedGet::VERSION,'request_digest'=>ProtectedGet::digest($request),
    'issued_at'=>$now,'expires_at'=>$now+30,'policy_revision'=>1,'id'=>$input['correlation']];
$sign = bin2hex(sodium_crypto_sign_detached(ProtectedGet::digest($grant),sodium_crypto_sign_secretkey($grantKeys)));
$fixture = new DigiForgePrintifyApi\SyntheticPrintifyTransport('107');
$fixture->restoreFixtureState([[],['product-1'=>['id'=>'product-1','shop_id'=>107,
    'title'=>'Synthetic mug','blueprint_id'=>42,'print_provider_id'=>7,'variants'=>[['id'=>9,'price'=>1000,'is_enabled'=>true]],
    'print_areas'=>[]]],['order-1'=>['id'=>'order-1','shop_id'=>107,'external_id'=>'fixture-order',
    'status'=>'partially-fulfilled','line_items'=>[['product_id'=>'product-1','variant_id'=>9,'quantity'=>1]],
    'shipments'=>[['carrier'=>'FixtureCarrier','number'=>'fixture-tracking-1','url'=>'https://example.invalid/receipt']]]],
    [],[],1,true]);
$gateway = new FixtureReadGateway(sodium_crypto_sign_publickey($grantKeys),
    static fn (): array => ['revision'=>1,'stop_all'=>false,'external_lock'=>false,'release_hold'=>false,'revoked'=>[]],
    static function (array $scope,string $method,string $path) use ($fixture,$request): array {
        $response = $fixture->request(['provider_shop_id'=>$scope['shop'],'method'=>$method,'endpoint'=>$path,
            'operation'=>$request['operation'],'body'=>[]]);
        return ['status'=>$response['status'],'body'=>json_encode($response['body'],JSON_THROW_ON_ERROR)];
    },$argv[2]);
echo json_encode($gateway->exchange($request,$grant,$sign,$now,sodium_crypto_sign_secretkey($replyKeys)),JSON_THROW_ON_ERROR);
