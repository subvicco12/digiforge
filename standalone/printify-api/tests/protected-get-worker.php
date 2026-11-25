<?php
declare(strict_types=1);

use DigiForgePrintifyApi\Gateway\FixtureReadGateway;
use DigiForgePrintifyApi\ProtectedGet;

define('ABSPATH', '/disposable/');
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
require $argv[1].'/ProtectedGet.php';
require $argv[1].'/SyntheticPrintifyTransport.php';
require __DIR__.'/../gateway/FixtureReadGateway.php';
$input = json_decode(stream_get_contents(STDIN, 1048576),true,32,JSON_THROW_ON_ERROR);
$port = $argv[3] ?? null;
if ($port === 'digest') {
    echo json_encode(['digest'=>ProtectedGet::digest($input)],JSON_THROW_ON_ERROR);
    return;
}
$request = $input['request'];
$now = time();
$scope = $request['scope'];
if ($scope['shop'] !== '107' || $scope['account'] !== 'fixture-account' || $scope['actor'] !== 1
    || $scope['integration'] !== 'fixture-read' || $scope['custody'] !== 'custody-ref:fixture') {
    throw new RuntimeException('Fixture scope denied');
}
$grantKeys = sodium_crypto_sign_seed_keypair(str_repeat('a',32));
$replyKeys = sodium_crypto_sign_seed_keypair(str_repeat('b',32));
if ($port === 'verify') {
    $grant = $input['grant'];
    $expected = ProtectedGet::request($scope,$request['operation'],$request['selection']);
    $digest = ProtectedGet::digest($expected);
    $verified = $request === $expected && $grant['request_digest'] === $digest
        && $grant['issuer'] === 'fixture-custodian' && sodium_crypto_sign_verify_detached(
            hex2bin($input['signature']),ProtectedGet::digest($grant),sodium_crypto_sign_publickey($grantKeys));
    // Quota scope survives session and profile changes. The complete intent digest still binds both.
    $quotaScope = array_intersect_key($scope,array_flip(['site','account','shop','integration']));
    echo json_encode(['verified'=>$verified,'request_digest'=>$digest,
        'scope_digest'=>ProtectedGet::digest($quotaScope),'path'=>$expected['path']],JSON_THROW_ON_ERROR);
    return;
}
if ($port === 'project') {
    echo json_encode(['validated'=>true,'data'=>ProtectedGet::project($request,$input['body'])],JSON_THROW_ON_ERROR);
    return;
}
$grant = ['issuer'=>'fixture-custodian','audience'=>ProtectedGet::VERSION,'request_digest'=>ProtectedGet::digest($request),
    'issued_at'=>$now,'expires_at'=>$now+30,'policy_revision'=>1,'id'=>$input['correlation'] ?? null];
$sign = bin2hex(sodium_crypto_sign_detached(ProtectedGet::digest($grant),sodium_crypto_sign_secretkey($grantKeys)));
if ($port === null && getenv('DF_PROTECTED_GET_WORKERS_FIXTURE') === '1') {
    $worker = __DIR__.'/../gateway/workers/fixture-runner.mjs';
    $process = proc_open(['node',$worker,PHP_BINARY,__FILE__,$argv[1],$argv[2]],
        [['pipe','r'],STDOUT,STDERR],$pipes);
    if (!is_resource($process)) { throw new RuntimeException('Workers fixture unavailable'); }
    fwrite($pipes[0],json_encode(['request'=>$request,'grant'=>$grant,'signature'=>$sign,'workspace'=>$input['workspace'] ?? null],JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    exit(proc_close($process));
}
$fixture = new DigiForgePrintifyApi\SyntheticPrintifyTransport('107');
$fixture->restoreFixtureState([[],['product-1'=>['id'=>'product-1','shop_id'=>107,
    'title'=>'Synthetic mug','blueprint_id'=>42,'print_provider_id'=>7,'variants'=>[['id'=>9,'price'=>1000,'is_enabled'=>true]],
    'print_areas'=>[]]],['order-1'=>['id'=>'order-1','shop_id'=>107,'external_id'=>'fixture-order',
    'status'=>'partially-fulfilled','line_items'=>[['product_id'=>'product-1','variant_id'=>9,'quantity'=>1]],
    'shipments'=>[['carrier'=>'FixtureCarrier','number'=>'fixture-tracking-1','url'=>'https://example.invalid/receipt']]]],
    [],[],1,true]);
if ($port === 'get') {
    echo json_encode($fixture->request(['provider_shop_id'=>$scope['shop'],'method'=>'GET',
        'endpoint'=>$request['path'],'operation'=>$request['operation'],'body'=>[]]),JSON_THROW_ON_ERROR);
    return;
}
$gateway = new FixtureReadGateway(sodium_crypto_sign_publickey($grantKeys),
    static fn (): array => ['revision'=>1,'stop_all'=>false,'external_lock'=>false,'release_hold'=>false,'revoked'=>[]],
    static function (array $scope,string $method,string $path) use ($fixture,$request): array {
        $response = $fixture->request(['provider_shop_id'=>$scope['shop'],'method'=>$method,'endpoint'=>$path,
            'operation'=>$request['operation'],'body'=>[]]);
        return ['status'=>$response['status'],'body'=>json_encode($response['body'],JSON_THROW_ON_ERROR)];
    },$argv[2]);
echo json_encode($gateway->exchange($request,$grant,$sign,$now,sodium_crypto_sign_secretkey($replyKeys)),JSON_THROW_ON_ERROR);
