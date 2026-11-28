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
        ['VARIANTS_READ',['blueprint_id'=>'42','provider_id'=>'7','show-out-of-stock'=>'1']],
        ['CATALOG_SHIPPING_METHOD_READ',['blueprint_id'=>'42','provider_id'=>'7','method'=>'standard']],
        ['PRODUCT_READ',['product_id'=>'product-1','blueprint_id'=>'42','provider_id'=>'7','variant_ids'=>'9']],
        ['MOCKUP_SELECTION_READ',['product_id'=>'product-1']],
        ['PRODUCT_ARTWORK_READ',['product_id'=>'product-1','blueprint_id'=>'42','provider_id'=>'7','variant_ids'=>'9','image_id'=>'image-1']],
        ['LINKED_IMAGE_METADATA_READ',['product_id'=>'product-1','image_id'=>'image-1']],
        ['PRODUCTS_READ',['page'=>'1']],
        ['ORDERS_READ',['page'=>'1','status'=>'partially-fulfilled']], ['ORDER_READ',['order_id'=>'order-1']],
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
        if ($operation==='LINKED_IMAGE_METADATA_READ') {
            $option = $direct ? 'digiforge_printify_direct_observation_v1' : 'digiforge_printify_get_observation_v1';
            $original = get_option($option);
            $originalArchive = get_option($option.'_archive');
            $originalRevision = $form['df_printify_get_workspace'];
            foreach (['projection', 'correlation', 'stale', 'mode', 'scope'] as $fault) {
                $changed = json_decode($original,true,32,JSON_THROW_ON_ERROR);
                $archive = json_decode($originalArchive,true,32,JSON_THROW_ON_ERROR);
                $last = array_key_last($archive['records']);
                $row = &$archive['records'][$last];
                if ($fault === 'projection') {
                    $changed['receipt']['data']['placements'][0]['position'] = 'tampered';
                } elseif ($fault === 'correlation') {
                    $changed['receipt']['correlation_id'] = str_repeat('e',32);
                } elseif ($fault === 'scope') {
                    $foreignScope = $profile + ['site'=>'wp-'.hash('sha256',home_url('/')), 'actor'=>1,
                        'session'=>hash_hmac('sha256',wp_get_session_token(),wp_salt('auth')), 'revision'=>str_repeat('a',64)];
                    $row['scope_sha256'] = DigiForgePrintifyApi\ProtectedGet::digest($foreignScope);
                } else {
                    if ($fault === 'stale') {
                        $changed['receipt']['observed_at'] = $row['provenance']['observed_at'] = time()-61;
                    } else {
                        $changed['receipt']['source'] = $row['provenance']['source'] =
                            $direct ? 'SYNTHETIC_PRINTIFY' : 'SYNTHETIC_DIRECT_PRINTIFY';
                    }
                    $row['provenance']['observation_sha256'] = DigiForgePrintifyApi\ProtectedGet::digest(
                        array_diff_key($row['provenance'],['observation_sha256'=>true]));
                }
                $row['record_sha256'] = DigiForgePrintifyApi\ProtectedGet::digest(array_diff_key($row,['record_sha256'=>true]));
                unset($row);
                update_option($option,json_encode($changed,JSON_THROW_ON_ERROR));
                update_option($option.'_archive',json_encode($archive,JSON_THROW_ON_ERROR));
                $form['df_printify_get_workspace'] = ProtectedGetWorkspace::snapshot($direct)['revision'];
                verify(ProtectedGetPanel::prepare($form)['reason']==='LINKED_ARTWORK_REQUIRED',
                    'Linked artwork admitted with '.$fault.' mismatch');
            }
            update_option($option,$original);
            update_option($option.'_archive',$originalArchive);
            $form['df_printify_get_workspace'] = $originalRevision;
            echo "Five linked-artwork evidence mismatches denied.\n";
            $foreign=$form;
            $foreign['df_printify_get']['product_id']='product-foreign';
            verify(ProtectedGetPanel::prepare($foreign)['reason']==='LINKED_ARTWORK_REQUIRED','Foreign linked artwork joined');
            $foreign['df_printify_get']=['product_id'=>'product-1','image_id'=>'image-foreign'];
            verify(ProtectedGetPanel::prepare($foreign)['reason']==='LINKED_ARTWORK_REQUIRED','Foreign image accessed');
        }
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
$scope = $profile + ['site'=>'wp-'.hash('sha256',home_url('/')), 'actor'=>1,
    'session'=>hash_hmac('sha256',wp_get_session_token(),wp_salt('auth')), 'revision'=>AccountProfile::revisionToken()];
$observation = ProtectedGetWorkspace::visible($scope,$direct);
$provenance = $observation['provenance'] ?? [];
verify(count($provenance)===($direct?7:8), 'Bounded provenance did not survive reopening');
$last=$provenance[array_key_last($provenance)];
verify($last['operation']==='ORDER_READ'
    && $last['response_sha256']===($direct?$observation['last_success']['response_sha256']:$observation['receipt']['response_sha256'])
    && $last['observation_sha256']===DigiForgePrintifyApi\ProtectedGet::digest(array_diff_key($last,['observation_sha256'=>true])),
    'Observation digest did not bind retained source and projection');
verify(ProtectedGetWorkspace::visible(array_replace($scope,['session'=>str_repeat('f',64)]),$direct)===null,
    'Another session accessed provenance');
verify(str_contains($html,'DF_READ_PROVENANCE_1') && !str_contains(json_encode($provenance,JSON_THROW_ON_ERROR),'fixture-tracking-1'),
    'Operator provenance missing or leaked payload');
echo "Bounded scope-fenced read provenance rendered; original metadata survived replay-safe reopening.\n";
$archive=ProtectedGetWorkspace::archivedProvenance($scope,$direct);
verify(count($archive)===10 && str_contains($html,'Archived read digests')
    && str_contains($html,'Read archive ready') && str_contains($html,'Mutation execution denied'), 'Append-only archive lost rolled-off history or operator view');
verify(ProtectedGetWorkspace::archivedProvenance(array_replace($scope,['session'=>str_repeat('f',64)]),$direct)===[],
    'Another session accessed archive');
if ($phase==='write') {
    $archiveOption=$direct?'digiforge_printify_direct_observation_v1_archive':'digiforge_printify_get_observation_v1_archive';
    $rawArchive = static function () use ($archiveOption): ?string {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $archiveOption));
    };
    $bytes=$rawArchive();
    $corrupt=json_decode($bytes,true,32,JSON_THROW_ON_ERROR);
    $corrupt['records'][0]['projection']['tampered']=true;
    update_option($archiveOption,json_encode($corrupt,JSON_THROW_ON_ERROR));
    verify(!ProtectedGetWorkspace::canArchive($direct) && ProtectedGetWorkspace::archivedProvenance($scope,$direct)===[],
        'Archive accepted changed projection');
    update_option($archiveOption,$bytes);
    $currentOption = substr($archiveOption, 0, -8);
    $receipt = $observation['last_success'] ?? $observation['receipt'];
    $receipt['correlation_id'] = str_repeat('b', 32);
    $before = ProtectedGetWorkspace::snapshot($direct)['revision'];

    $firstConnection = $GLOBALS['wpdb'];
    $secondConnection = new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
    $secondConnection->options = $firstConnection->options;
    $form['df_printify_get_workspace'] = $before;
    verify(ProtectedGetWorkspace::withArchiveAdmission($direct, static function () use (
        $firstConnection,$secondConnection,$direct,$form,$scope,$before,$receipt
    ): bool {
        $GLOBALS['wpdb'] = $secondConnection;
        try {
            return ProtectedGetPanel::prepare($form)['reason']==='ARCHIVE_BUSY'
                && !ProtectedGetWorkspace::persist($scope,$before,$receipt,$direct);
        } finally {
            $GLOBALS['wpdb'] = $firstConnection;
        }
    },false), 'Competing database connection entered the archive admission window');
    $secondConnection->close();
    echo "Competing database connection denied archive dispatch and append.\n";

    // A one-shot write fault after the transactional archive append.
    $archiveWriteSeen = false;
    $fault = static function(string $sql) use ($archiveOption, $currentOption, &$archiveWriteSeen): string {
        if (str_starts_with($sql, 'INSERT INTO') && str_contains($sql, "'".$archiveOption."'")) {
            $archiveWriteSeen = true;
        }
        if ($archiveWriteSeen && str_starts_with($sql, 'INSERT INTO') && str_contains($sql, "'".$currentOption."'")) {
            return 'SELECT 1 WHERE 0';
        }
        return $sql;
    };
    add_filter('query', $fault);
    $persisted = ProtectedGetWorkspace::persist($scope, $before, $receipt, $direct);
    remove_filter('query', $fault);
    verify($archiveWriteSeen, 'Forced failure did not reach the archive append');
    verify(!$persisted, 'Injected current write returned success');
    verify(ProtectedGetWorkspace::snapshot($direct)['revision']===$before, 'Current row changed on forced failure');
    verify($rawArchive()===$bytes, 'Failed current write did not roll back archive append byte for byte');
    echo "Archive append and current-row write rolled back together.\n";

    $large = json_decode($bytes,true,32,JSON_THROW_ON_ERROR);
    $record = array_diff_key(end($large['records']), ['record_sha256'=>true]);
    $record['previous_sha256'] = end($large['records'])['record_sha256'];
    $record['projection'] = ['synthetic_padding'=>str_repeat('x', 3900000)];
    $record['provenance']['projection_sha256'] = DigiForgePrintifyApi\ProtectedGet::digest($record['projection']);
    $record['provenance']['observation_sha256'] = DigiForgePrintifyApi\ProtectedGet::digest(
        array_diff_key($record['provenance'], ['observation_sha256'=>true]));
    $large['records'][] = $record+['record_sha256'=>DigiForgePrintifyApi\ProtectedGet::digest($record)];
    $largeBytes = json_encode($large,JSON_THROW_ON_ERROR);
    verify(strlen($largeBytes)<4194304,'Fixture exceeds archive ceiling');
    update_option($archiveOption,$largeBytes);
    verify(!ProtectedGetWorkspace::canArchive($direct),'Byte capacity ignored below row limit');
    $attempts=$direct?$calls:count(json_decode(file_get_contents($journal),true));
    $form['df_printify_get_workspace']=$before;
    verify(ProtectedGetPanel::prepare($form)['reason']==='ARCHIVE_CAPACITY_EXHAUSTED'
        && ($direct?$calls:count(json_decode(file_get_contents($journal),true)))===$attempts,
        'Byte capacity triggered provider dispatch');
    update_option($archiveOption,$bytes);
    echo "Byte headroom enforced before transport.\n";
    $filled=json_decode($bytes,true,32,JSON_THROW_ON_ERROR);
    $previous=end($filled['records']);
    while (count($filled['records'])<64) {
        $record=array_diff_key($previous,['record_sha256'=>true]);
        $record['previous_sha256']=$previous['record_sha256'];
        $previous=$record+['record_sha256'=>DigiForgePrintifyApi\ProtectedGet::digest($record)];
        $filled['records'][]=$previous;
    }
    $full=json_encode($filled,JSON_THROW_ON_ERROR);
    update_option($archiveOption,$full);
    $attempts=$direct?$calls:count(json_decode(file_get_contents($journal),true));
    $form['df_printify_get_workspace']=ProtectedGetWorkspace::snapshot($direct)['revision'];
    verify(ProtectedGetPanel::prepare($form)['reason']==='ARCHIVE_CAPACITY_EXHAUSTED'
        && ($direct?$calls:count(json_decode(file_get_contents($journal),true)))===$attempts
        && $rawArchive()===$full,'Archive capacity triggered a read or silent pruning');
    ob_start();
    ProtectedGetPanel::render([]);
    verify(str_contains(ob_get_clean(),'Read archive held'),'Full archive status not shown.');
    update_option($archiveOption,$bytes);
    echo "Full archive denied dispatch without pruning protected records.\n";
}
echo "Ten append-only records, scope isolation and tamper denial PASS\n";
verify(get_option('active_plugins')===['digiforge-printify-api/digiforge-printify-api.php'],'Unexpected active plugin');
echo $phase.': standalone-only WordPress panel rendered persisted synthetic observation; provider HTTP trapped.'.PHP_EOL;
