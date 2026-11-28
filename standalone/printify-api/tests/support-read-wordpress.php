<?php
declare(strict_types=1);

use DigiForgePrintifyApi\{AccountProfile,ProtectedGetPanel,ProtectedGetWorkspace};

$root = getenv('DF_PRINTIFY_WP_FIXTURE_ROOT');
$socket = getenv('DF_PRINTIFY_WP_FIXTURE_SOCKET');
if (!is_string($root) || !str_starts_with($root,'/tmp/df-printify-')
    || !is_string($socket) || !str_starts_with($socket,'/tmp/df-printify-')) {
    throw new RuntimeException('Disposable fixture required');
}
define('DIGIFORGE_PRINTIFY_DIRECT_GET_FIXTURE',true);
define('DIGIFORGE_PRINTIFY_DIRECT_READ_SCOPE',
    ['shop'=>'107','account'=>'fixture-account','integration'=>'fixture-read','custody'=>'custody-ref:fixture']);
define('DIGIFORGE_PRINTIFY_DIRECT_FIXTURE_PAT','fixture-test-only');
require $root.'/wp-load.php';
if (DB_HOST !== 'localhost:'.$socket || DB_NAME !== 'printify_fixture' || wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Socket-only local fixture required');
}
require WP_PLUGIN_DIR.'/digiforge-printify-api/ProtectedGetPanel.php';
wp_set_current_user(1);
$expires=time()+600;
$token=WP_Session_Tokens::get_instance(1)->create($expires);
$_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie(1,$expires,'logged_in',$token);
add_filter('digiforge_printify_direct_fixture_gates',static fn()=>['stop_all'=>false,'external_lock'=>false]);
$paths=[];
$foreign=false;
add_filter('digiforge_printify_direct_fixture_response',static function ($previous,array $request) use (&$paths,&$foreign): array {
    $paths[]=$request['path'];
    if ($request['operation']==='PRODUCT_GPSR_READ') {
        $text=$foreign?'https://unsupported.example.invalid/link':'Fixture product safety text';
        return ['response'=>['code'=>200],'headers'=>['content-type'=>'application/json'],
            'body'=>json_encode([['title'=>'Fixture instructions','text'=>$text]],JSON_THROW_ON_ERROR)];
    }
    $item=['id'=>'support-1','order_id'=>$foreign?'foreign-order':'order-1','type'=>'order_refund',
        'status'=>'resolved','outcome'=>'positive','line_item_ids'=>['line-1'],
        'created_at'=>'2026-11-19T00:00:00Z','updated_at'=>'2026-11-20T00:00:00Z',
        'description'=>'fixture-private-description'];
    return ['response'=>['code'=>200],'headers'=>['content-type'=>'application/json'],
        'body'=>json_encode(['data'=>$request['operation']==='SUPPORT_LIST'?[$item]:$item],JSON_THROW_ON_ERROR)];
},10,2);
$read=static fn(string $operation,array $fields): array=>ProtectedGetPanel::prepare([
    'df_printify_get_operation'=>$operation,'df_printify_get'=>$fields,
    'df_printify_get_nonce'=>[$operation=>wp_create_nonce('df_printify_protected_get_v1:'.$operation)],
    'df_printify_get_revision'=>AccountProfile::revisionToken(),
    'df_printify_get_workspace'=>ProtectedGetWorkspace::snapshot(true)['revision'],
]);
$check=static function(bool $ok,string $reason): void {if (!$ok) throw new RuntimeException($reason);};
$list=['order_id'=>'order-1'];
$detail=$list+['support_request_id'=>'support-1'];
$check($read('SUPPORT_LIST',$list)['state']==='OBSERVED','Scoped support list failed');
ob_start();
ProtectedGetPanel::render([]);
$html=ob_get_clean();
$check(str_contains($html,'Read selected support request') && !str_contains($html,'fixture-private-description'),
    'Support navigation missing or description exposed');
$check($read('SUPPORT_DETAIL',$detail)['state']==='OBSERVED','Scoped support detail failed');
$foreign=true;
$check($read('SUPPORT_DETAIL',$detail)['state']==='UNAVAILABLE','Foreign order evidence was admitted');
$check($paths===['/v1/shops/107/orders/order-1/support-requests',
    '/v1/shops/107/orders/order-1/support-requests/support-1',
    '/v1/shops/107/orders/order-1/support-requests/support-1'],'Unexpected fixture routes or automatic retry');
echo "Installed WordPress support list/detail, scoped navigation, redaction and foreign order denial PASS\n";
$foreign=false;
$gpsr=['product_id'=>'product-1','blueprint_id'=>'42','provider_id'=>'7','variant_ids'=>'9'];
$check($read('PRODUCT_GPSR_READ',$gpsr)['state']==='OBSERVED','Scoped product safety evidence failed');
ob_start();
ProtectedGetPanel::render([]);
$html=ob_get_clean();
$check(str_contains($html,'Fixture product safety text') && str_contains($html,'supplier_certified'),
    'Product safety provenance was not rendered');
$foreign=true;
$check($read('PRODUCT_GPSR_READ',$gpsr)['state']==='UNAVAILABLE','Unsafe GPSR text passed validation');
$check(array_slice($paths,-2)===array_fill(0,2,'/v1/shops/107/products/product-1/gpsr.json'),
    'Product safety request escaped the scoped endpoint');
echo "Installed WordPress protected product safety evidence and unsafe response denial PASS\n";
