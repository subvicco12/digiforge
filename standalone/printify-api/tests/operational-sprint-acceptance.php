<?php
declare(strict_types=1);

use DigiForgePrintifyApi\Runtime;
use DigiForgePrintifyApi\Engine\POD\PrintifyProductRequest;

$pair=sodium_crypto_sign_seed_keypair(hash('sha256','public-test-only-custody-mock-v1',true));
$scope=['shop'=>'107','account_reference'=>'fixture-account','credential_reference'=>'fixture-custody-read',
    'source_sha'=>str_repeat('b',40),'source_revision'=>2,'authorization_revision'=>4,
    'signer_key'=>base64_encode(sodium_crypto_sign_publickey($pair))];
$calls=0;
$exchange=static function(array $request,int $deadline,int $maximum) use (&$calls):array {
    ++$calls;
    $server=proc_open(['node',__DIR__.'/operational-sprint-process.mjs'],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($server)) throw new RuntimeException('Loopback fixture startup failed');
    try {
        fwrite($pipes[0],json_encode($request,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n");
        fclose($pipes[0]);
        stream_set_timeout($pipes[1],intdiv($deadline,1000));
        $wire=stream_get_contents($pipes[1],$maximum+4096);
        if (stream_get_meta_data($pipes[1])['timed_out'] || !is_string($wire) || strlen($wire)>=$maximum+4096)
            throw new RuntimeException('Loopback fixture read bound');
        $errors=stream_get_contents($pipes[2],4096);
        if ($errors!=='') fwrite(STDERR,$errors);
        return json_decode($wire,true,12,JSON_THROW_ON_ERROR);
    } finally {
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_terminate($server);
        proc_close($server);
    }
};

$blocked=Runtime::readFromCustody($scope,'ORDER_READ',['order_id'=>'order-1'],$exchange);
requireFixture($blocked['state']==='BLOCKED' && $calls===0,'Operational network gate opened');
$read=static function(string $operation,array $input) use ($scope,$exchange):array {
    $result=Runtime::readFromProviderSimulation($scope,$operation,$input,$exchange);
    requireFixture($result['state']==='AUTHENTICATED_PROVIDER_TRANSPORT_SIMULATION'
        && !$result['provider_outcome_confirmed'] && !$result['provider_execution_authorized'],
        'Signed provider simulation unavailable or mislabeled: '.$operation);
    return $result['observation'];
};
$catalog=$read('PROVIDERS_READ',[]);
$product=$read('PRODUCT_READ',['product_id'=>'product-1','blueprint_id'=>42,'provider_id'=>7,'variant_ids'=>[9]]);
requireFixture($catalog['providers'][0]['id']===$product['print_provider_id'],'Product not bound to catalog provider');
require_once WP_PLUGIN_DIR.'/digiforge-printify-api/Engine/POD/PrintifyProductRequest.php';
$plan=PrintifyProductRequest::prepare('PRODUCT_CREATE','107',[
    'title'=>'Prepared product','description'=>'Synthetic only','blueprint_id'=>$product['blueprint_id'],
    'print_provider_id'=>$product['print_provider_id'],'variants'=>$product['variants'],
    'print_areas'=>[['variant_ids'=>[9],'placeholders'=>[['position'=>'front',
        'images'=>[['id'=>'fixture-art','x'=>0.5,'y'=>0.5,'scale'=>1,'angle'=>0]]]]]],
]);
requireFixture($plan['external_execution_authorized']===false,'Product plan became authorizing');
foreach (['REGISTER','CLAIM','RECEIPT_READ'] as $action) {
    $native=Runtime::prepareNativeMutation('107','fixture-account',['action'=>$action]);
    requireFixture($native['state']==='BLOCKED' && !$native['provider_execution_authorized'],
        'Native admission bypassed: '.$action);
}
// Separate simulated observation. Never resolves the held mutation as a provider outcome.
$order=$read('ORDER_READ',['order_id'=>'order-1']);
requireFixture($order['line_items'][0]['product_id']===$product['id']
    && $order['shipments'][0]['tracking_hash']===hash('sha256','fixture-tracking') && $calls===3,
    'Order or tracking correlation failed');
echo "Scoped custody simulation → product preparation → Core denial → isolated tracking PASS\n";
