<?php
declare(strict_types=1);
class WP_Error {
    public function __construct(public string $code, public string $message, public array $data = []) {}
}
function is_wp_error(mixed $v): bool { return $v instanceof WP_Error; }
function wp_remote_retrieve_response_code(array $r): int { return $r['status']; }
function wp_remote_retrieve_header(array $r, string $name): string { return $r['retry_after'] ?? ''; }
function wp_remote_retrieve_body(array $r): string { return $r['body'] ?? ''; }
require dirname(__DIR__) . '/includes/Security/Logger.php';
require dirname(__DIR__) . '/includes/POD/PrintifyCatalogReadContract.php';
require dirname(__DIR__) . '/includes/POD/PrintifyCatalogReadPolicy.php';
require dirname(__DIR__) . '/includes/POD/PrintifyCatalogClient.php';
use DigiForge\POD\PrintifyCatalogClient;
function ensure(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$calls = 0;
$authorized = 0;
$sender = static function (string $url, array $args) use (&$calls): array {
    ++$calls;
    return $calls === 1 ? ['status'=>429,'retry_after'=>'0'] : ['status'=>200,'body'=>'{"variants":[]}'];
};
$guard = static function (string $url, int $attempt) use (&$authorized): bool {
    ++$authorized;
    return $attempt === 1;
};
$client = new PrintifyCatalogClient($sender, static fn(int $delay): null => null, $guard);
$result = $client->variants('test-token', 478, 28);
ensure($result instanceof WP_Error && $result->code === 'digiforge_printify_read_authorization', 'Retry must be denied');
ensure($calls === 1 && $authorized === 2, 'No second transport after denied retry');

$calls = 0;
$client = new PrintifyCatalogClient($sender, static fn(int $delay): null => null, static fn(): WP_Error => new WP_Error('locked', 'STOP ALL'));
$result = $client->variants('test-token', 478, 28);
ensure($result instanceof WP_Error && $result->code === 'locked' && $calls === 0, 'Explicit denial must precede network');
$client = new PrintifyCatalogClient($sender, static fn(int $delay): null => null, static function (): bool { throw new RuntimeException('failure'); });
$result = $client->variants('test-token', 478, 28);
ensure($result instanceof WP_Error && $calls === 0, 'Guard exceptions must deny before network');
$client = new PrintifyCatalogClient($sender, null, static fn()=>true);
$result = $client->scopedRead('synthetic-token',['kind'=>'variants','blueprint_id'=>478,'provider_id'=>28]);
ensure($result instanceof WP_Error && $calls===0, 'Missing grant dependency and permissive callback must not authorize scoped transport');
$legacyCalls=0;
$legacy = new PrintifyCatalogClient(static function($url,$args)use(&$legacyCalls){++$legacyCalls;return ['status'=>200,'body'=>'{"variants":[]}'];});
ensure(is_array($legacy->variants('synthetic-token',478,28)) && $legacyCalls===1,'Legacy unscoped caller contract preserved');
foreach(['not JSON','null',str_repeat('x',1048577),'{"value":"synthetic-token"}','{"access_token":"redacted"}']as $body){
 $bad=new PrintifyCatalogClient(static fn()=>['status'=>200,'body'=>$body]);
 ensure($bad->variants('synthetic-token',478,28) instanceof WP_Error,'Malformed or protected legacy payload rejected');
}
$throwing = new PrintifyCatalogClient(static function(){throw new RuntimeException('sensitive detail');});
$result=$throwing->variants('synthetic-token',478,28);
ensure($result instanceof WP_Error && !str_contains(json_encode($result),'sensitive detail'),'Thrown transport details sanitized');
foreach ([['kind'=>'shipping','blueprint_id'=>478,'provider_id'=>28],['kind'=>'variants','blueprint_id'=>-1,'provider_id'=>28],['kind'=>'variants','blueprint_id'=>478,'provider_id'=>28,'url'=>'https://evil.invalid'],['kind'=>'variants','blueprint_id'=>'478','provider_id'=>28]] as $request) {
 ensure($client->scopedRead('synthetic-token',$request) instanceof WP_Error && $calls===0,'Unsafe explicit scope denied before HTTP');
}
$exhausted=0;$sleeps=[];
$rate=new PrintifyCatalogClient(static function()use(&$exhausted){++$exhausted;return ['status'=>429,'retry_after'=>'999999999'];},static function($delay)use(&$sleeps){$sleeps[]=$delay;});
ensure($rate->variants('synthetic-token',478,28) instanceof WP_Error && $exhausted===3 && $sleeps===[60000000,60000000],'Legacy retry count and delay caps preserved');
echo "Printify catalog attempt authorization offline tests passed\n";
