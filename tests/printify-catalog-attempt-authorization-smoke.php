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
// Callback-only legacy entry points cannot replace concrete signed admission.
// Positive transport/provenance/rate-limit cases run through the real grant
// repository in PrintifyCatalogResponseSafetyTest, never a permissive stub.
$calls=0;$guardCalls=0;$sleeps=[];
$client=new PrintifyCatalogClient(static function()use(&$calls){++$calls;return ['status'=>429,'retry_after'=>'0','body'=>'{}'];},static function($delay)use(&$sleeps){$sleeps[]=$delay;},static function()use(&$guardCalls){++$guardCalls;return true;});
foreach ([static fn()=>$client->blueprints('synthetic-token'),static fn()=>$client->blueprint('synthetic-token',478),static fn()=>$client->providers('synthetic-token',478),static fn()=>$client->variants('synthetic-token',478,28),static fn()=>$client->variantsWithEvidence('synthetic-token',478,28)] as $read) {
 $result=$read();ensure($result instanceof WP_Error&&$result->code==='catalog_governed_read_required','Raw legacy API cannot bypass signed grant and reservation');
}
foreach([null,'standard','priority','express','economy']as $method)ensure($client->shipping('synthetic-token',478,28,$method) instanceof WP_Error,'Raw shipping cannot bypass signed admission');
ensure($client->shipping('synthetic-token',478,28,'POST') instanceof WP_Error,'Unsupported shipping method denied');
ensure($calls===0&&$guardCalls===0&&$sleeps===[],'No transport, permissive callback or retry before concrete admission');
$result=$client->scopedRead('synthetic-token',['kind'=>'variants','blueprint_id'=>478,'provider_id'=>28]);
ensure($result instanceof WP_Error&&$calls===0,'Missing grant dependency cannot authorize scoped transport');
foreach ([['kind'=>'shipping_method','blueprint_id'=>478,'provider_id'=>28,'method'=>'POST'],['kind'=>'variants','blueprint_id'=>-1,'provider_id'=>28],['kind'=>'variants','blueprint_id'=>478,'provider_id'=>28,'url'=>'https://evil.invalid'],['kind'=>'variants','blueprint_id'=>'478','provider_id'=>28]] as $request) {
 ensure($client->scopedRead('synthetic-token',$request) instanceof WP_Error&&$calls===0,'Unsafe explicit scope denied before HTTP');
}
foreach ([
 [['kind'=>'blueprint','blueprint_id'=>478],['id'=>478]],
 [['kind'=>'providers','blueprint_id'=>478],[['id'=>28,'title'=>'synthetic']]],
 [['kind'=>'variants','blueprint_id'=>478,'provider_id'=>28],['variants'=>[]]],
] as [$request,$data]) ensure(DigiForge\POD\PrintifyCatalogReadContract::select($data,$request)===$data,'Supported envelope retained without fabricated geometry');
foreach([null,'standard','priority','express','economy']as $method){
 $request=['kind'=>$method===null?'shipping':'shipping_method','blueprint_id'=>478,'provider_id'=>28];if($method!==null)$request['method']=$method;
 $suffix=$method===null?'.json':'/'.$method.'.json';
 ensure(DigiForge\POD\PrintifyCatalogReadContract::endpoint($request)==='/v2/catalog/blueprints/478/print_providers/28/shipping'.$suffix,'Exact supported shipping GET path retained');
 ensure(DigiForge\POD\PrintifyCatalogReadContract::select(['data'=>[]],$request)===['data'=>[]],'Shipping envelope retained without fabricated evidence');
}
echo "Printify catalog concrete admission offline tests passed\n";
