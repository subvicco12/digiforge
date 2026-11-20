<?php
declare(strict_types=1);

use DigiForgePrintifyApi\Runtime;

$pair=sodium_crypto_sign_seed_keypair(hash('sha256','public-test-only-custody-mock-v1',true));
$scope=['shop'=>'107','account_reference'=>'fixture-account','credential_reference'=>'fixture-custody-read',
    'source_sha'=>str_repeat('b',40),'source_revision'=>2,'authorization_revision'=>4,
    'signer_key'=>base64_encode(sodium_crypto_sign_publickey($pair))];
$calls=0;
$exchange=static function(array $request,int $timeout,int $maximum) use (&$calls): array {
    ++$calls;
    $server=proc_open([PHP_BINARY,__DIR__.'/custody-mock-server.php'],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($server)) {
        throw new RuntimeException('Fixture server failed to start');
    }
    try {
        fwrite($pipes[0],json_encode($request,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n");
        fclose($pipes[0]);
        stream_set_timeout($pipes[1],intdiv($timeout,1000));
        $bytes=stream_get_contents($pipes[1],$maximum+4096);
        if (stream_get_meta_data($pipes[1])['timed_out'] || !is_string($bytes)
            || strlen($bytes)>=$maximum+4096) {
            throw new RuntimeException('Fixture server timeout or response bound');
        }
        return json_decode($bytes,true,12,JSON_THROW_ON_ERROR);
    } finally {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_terminate($server);
        proc_close($server);
    }
};
$http=0;
$tripwire=static function() use (&$http): never {
    ++$http;
    throw new RuntimeException('Custody fixture attempted WordPress HTTP');
};
add_filter('pre_http_request',$tripwire,PHP_INT_MAX);
try {
    $held=Runtime::readFromCustody($scope,'ORDER_READ',['order_id'=>'order-1'],$exchange);
    if ($held['state']!=='BLOCKED' || $calls!==0) {
        throw new RuntimeException('Held operation reached isolated custody server');
    }
    $fixture=Runtime::readFromCustody($scope,'ORDER_READ',['order_id'=>'order-1'],$exchange,true);
    if ($fixture['state']!=='AUTHENTICATED_CUSTODY_FIXTURE' || $fixture['observation']['status']!=='fulfilled'
        || $calls!==1 || $fixture['provider_outcome_confirmed'] || $fixture['provider_execution_authorized']) {
        throw new RuntimeException('Isolated custody server evidence failed');
    }
} finally {
    remove_filter('pre_http_request',$tripwire,PHP_INT_MAX);
}
if ($http!==0) {
    throw new RuntimeException('Custody fixture issued an external HTTP request');
}
echo "Isolated custody fixture acceptance PASS\n";
