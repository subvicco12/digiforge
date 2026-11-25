<?php
declare(strict_types=1);

use DigiForgePrintifyApi\AccountProfile;
use DigiForgePrintifyApi\ProtectedGetPanel;
use DigiForgePrintifyApi\ProtectedGetWorkspace;

$root = getenv('DF_PRINTIFY_WP_FIXTURE_ROOT');
$socket = getenv('DF_PRINTIFY_WP_FIXTURE_SOCKET');
$phase = $argv[1] ?? '';
if (!is_string($root) || !str_starts_with($root,'/tmp/df-printify-')
    || !is_string($socket) || !str_starts_with($socket,'/tmp/df-printify-')
    || !in_array($phase,['write','reopen'],true)) {
    throw new RuntimeException('Isolated fixture required');
}
$direct = getenv('DF_PRINTIFY_DIRECT_FIXTURE') === '1';
$fixtureProfile = ['shop'=>'107','account'=>'fixture-account','integration'=>'fixture-read','custody'=>'custody-ref:fixture'];
if ($direct) {
    define('DIGIFORGE_PRINTIFY_DIRECT_GET_FIXTURE',true);
    define('DIGIFORGE_PRINTIFY_DIRECT_READ_SCOPE',$fixtureProfile);
    define('DIGIFORGE_PRINTIFY_DIRECT_FIXTURE_PAT','fixture-test-only');
} else {
    define('DIGIFORGE_PRINTIFY_PROTECTED_GET_FIXTURE',true);
    define('DIGIFORGE_PRINTIFY_GET_HTTP_FIXTURE',true);
    define('DIGIFORGE_PRINTIFY_FIXTURE_REPLY_KEY',bin2hex(sodium_crypto_sign_publickey(
        sodium_crypto_sign_seed_keypair(str_repeat('b',32)))));
}
require $root.'/wp-load.php';
if (DB_HOST !== 'localhost:'.$socket || DB_NAME !== 'printify_fixture' || wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Local fixture database required');
}
add_filter('pre_http_request',static function (mixed $prior,array $args,string $url) use ($direct): WP_Error {
    if ($direct ? !str_starts_with($url,'https://api.printify.com/v') : $url !== 'https://protected-get.example.invalid/read') {
        throw new RuntimeException('Unexpected HTTP');
    }
    return new WP_Error('fixture_requires_scoped_interception');
},PHP_INT_MAX,3);
wp_set_current_user(1);
$plugin = WP_PLUGIN_DIR.'/digiforge-printify-api';
require $plugin.'/ProtectedGetPanel.php';
function verify(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$state = dirname($root).'/fixture.state';
if ($phase === 'write') {
    $expires = time()+600;
    $token = WP_Session_Tokens::get_instance(1)->create($expires);
    $cookie = wp_generate_auth_cookie(1,$expires,'logged_in',$token);
    file_put_contents($state,$cookie);
    chmod($state,0600);
} else {
    $cookie = file_get_contents($state);
}
$_COOKIE[LOGGED_IN_COOKIE] = $cookie;
$profile = $fixtureProfile;
$journal = dirname($root).'/gateway.json';
$stopped = false;
if ($direct) {
    add_filter('digiforge_printify_direct_fixture_gates',static function () use (&$stopped): array {
        return ['stop_all'=>$stopped,'external_lock'=>false];
    });
}
if ($phase === 'write') {
    verify(AccountProfile::save($profile,wp_create_nonce('digiforge_printify_account_profile_save'))==='SAVED_LOCAL_METADATA','Profile save failed');
    file_put_contents($journal,'[]');
    $worker = static function (mixed $previous,array $request,string $correlation,string $workspace) use ($plugin,$journal): array {
        $process = proc_open([PHP_BINARY,__DIR__.'/protected-get-worker.php',$plugin,$journal],
            [['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
        if (!is_resource($process)) { throw new RuntimeException('Worker unavailable'); }
        fwrite($pipes[0],json_encode(['request'=>$request,'correlation'=>$correlation,'workspace'=>$workspace],JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $output=stream_get_contents($pipes[1]);
        $stderr=stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process)!==0) { throw new RuntimeException('Worker failed: '.$stderr); }
        return json_decode($output,true,24,JSON_THROW_ON_ERROR);
    };
    $form = ['df_printify_get_nonce'=>['SHOPS_READ'=>wp_create_nonce('df_printify_protected_get_v1:SHOPS_READ')],
        'df_printify_get_revision'=>AccountProfile::revisionToken(),'df_printify_get_workspace'=>ProtectedGetWorkspace::snapshot($direct)['revision'],
        'df_printify_get_operation'=>'SHOPS_READ','df_printify_get'=>[]];
    $response = ProtectedGetPanel::prepare($form);
    verify($response['state']==='BLOCKED','Missing transport did not fail closed');
    if ($direct) {
        $calls = 0;
        add_filter('digiforge_printify_direct_fixture_response',static function ($previous,array $request) use ($plugin,&$calls,$journal): array {
            ++$calls;
            $process = proc_open([PHP_BINARY,__DIR__.'/protected-get-worker.php',$plugin,$journal,'get'],
                [['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('Fixture unavailable');
            fwrite($pipes[0],json_encode(['request'=>$request],JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process)!==0) throw new RuntimeException('Fixture read failed: '.$error);
            $reply = json_decode($output,true,24,JSON_THROW_ON_ERROR);
            return ['response'=>['code'=>$reply['status']],'headers'=>['content-type'=>'application/json'],
                'body'=>json_encode($reply['body'],JSON_THROW_ON_ERROR)];
        },10,2);
        $stopped = true;
        verify(ProtectedGetPanel::prepare($form)['state']==='BLOCKED' && $calls===0,'STOP fixture produced HTTP');
        $stopped = false;
    } else {
        add_filter('digiforge_printify_protected_get_http_fixture', static function ($previous,array $wire) use ($worker): array {
            return ['response'=>['code'=>200],'headers'=>['content-type'=>'application/json'],
                'body'=>json_encode($worker(null,$wire['request'],$wire['correlation'],$wire['workspace']),JSON_THROW_ON_ERROR)];
        },10,2);
        verify(ProtectedGetPanel::prepare($form)['reason']==='WORKSPACE_UNAVAILABLE_OR_STALE','Stale workspace was reused');
    }
    $form['df_printify_get_workspace'] = ProtectedGetWorkspace::snapshot($direct)['revision'];
    $response = ProtectedGetPanel::prepare($form);
    verify($response['state']==='OBSERVED','Signed exchange: '.$response['reason']);
    verify(ProtectedGetPanel::prepare($form)['reason']==='WORKSPACE_UNAVAILABLE_OR_STALE','Duplicate workspace was reused');
    $cases = [
        ['BLUEPRINTS_READ',[]], ['BLUEPRINT_PROVIDERS_READ',['blueprint_id'=>'42']],
        ['VARIANTS_READ',['blueprint_id'=>'42','provider_id'=>'7']],
        ['VARIANT_READ',['blueprint_id'=>'42','provider_id'=>'7','variant_id'=>'9']],
        ['CATALOG_SHIPPING_METHOD_READ',['blueprint_id'=>'42','provider_id'=>'7','method'=>'standard']],
        ['PRODUCTS_READ',['page'=>'1']],
        ['PRODUCT_READ',['product_id'=>'product-1','blueprint_id'=>'42','provider_id'=>'7','variant_ids'=>'9']],
        ['ORDERS_READ',['page'=>'1']], ['ORDER_READ',['order_id'=>'order-1']],
    ];
    foreach ($cases as [$operation,$selection]) {
        $form['df_printify_get_workspace'] = ProtectedGetWorkspace::snapshot($direct)['revision'];
        $form['df_printify_get_operation'] = $operation;
        $form['df_printify_get'] = $selection;
        $denied = $form;
        $denied['df_printify_get_nonce'] = [$operation=>wp_create_nonce('df_printify_protected_get_v1:SHOPS_READ')];
        verify(ProtectedGetPanel::prepare($denied)['reason']==='OPERATOR_DENIED',$operation.': accepted another operation nonce');
        verify(ProtectedGetWorkspace::snapshot($direct)['revision']===$form['df_printify_get_workspace'],
            $operation.': denied nonce changed workspace');
        $form['df_printify_get_nonce'] = [$operation=>wp_create_nonce('df_printify_protected_get_v1:'.$operation)];
        $response = ProtectedGetPanel::prepare($form);
        verify($response['state']==='OBSERVED',$operation.': '.$response['reason']);
    }
    $legacy = $form;
    $legacy['df_printify_get_nonce'] = ['ORDER_READ'=>wp_create_nonce('df_printify_protected_get_v1')];
    verify(ProtectedGetPanel::prepare($legacy)['reason']==='OPERATOR_DENIED','Legacy shared nonce was accepted');
    echo "Nine cross-operation nonce substitutions and the legacy shared nonce denied.\n";
    if ($direct) {
        verify($calls===10,'Unexpected direct call count');
        $form['df_printify_get_workspace'] = ProtectedGetWorkspace::snapshot(true)['revision'];
        verify(ProtectedGetPanel::prepare($form)['reason']==='LOCAL_BUDGET_OR_STORAGE_UNAVAILABLE' && $calls===10,
            'Durable direct quota was bypassed');
        verify((ProtectedGetWorkspace::snapshot(true)['data']['last_success']['state'] ?? null)==='OBSERVED',
            'Quota denial removed prior observation');
    } else {
        verify(count(json_decode(file_get_contents($journal),true))===10,'Unexpected grants admitted');
    }
    $form['df_printify_get_workspace'] = ProtectedGetWorkspace::snapshot($direct)['revision'];
    $stale = $form;
    $stale['df_printify_get_revision'] = str_repeat('0',64);
    verify(ProtectedGetPanel::prepare($stale)['reason']==='SCOPE_OR_REVISION_UNAVAILABLE','Stale profile was admitted');
    $manager = WP_Session_Tokens::get_instance(1);
    $oldToken = wp_get_session_token();
    $session = $manager->get($oldToken);
    $manager->destroy($oldToken);
    $form['df_printify_get_nonce'] = ['ORDER_READ'=>wp_create_nonce('df_printify_protected_get_v1:ORDER_READ')];
    verify(ProtectedGetPanel::prepare($form)['state']==='BLOCKED','Destroyed session was admitted');
    $manager->update($oldToken,$session);
    echo $direct
        ? "10 direct synthetic operator reads. STOP, durable quota, stale profile/workspace and destroyed session denied.\n"
        : "10 signed operator selectors through separate gateway processes. Stale profile, stale workspace and destroyed session denied.\n";
}
ob_start();
ProtectedGetPanel::render([]);
$html = ob_get_clean();
verify(str_contains($html,'partially-fulfilled') && str_contains($html,'tracking_hash') && str_contains($html,'Recent fixture')
    && (!$direct || (str_contains($html,'tracking_reference') && str_contains($html,'Stale prior synthetic'))),
    'Stored observation not shown');
verify(get_option('active_plugins')===['digiforge-printify-api/digiforge-printify-api.php'],'Unexpected active plugin');
echo $phase.': standalone-only WordPress panel rendered persisted synthetic observation; provider HTTP trapped.'.PHP_EOL;
