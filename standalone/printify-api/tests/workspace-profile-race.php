<?php
declare(strict_types=1);

use DigiForgePrintifyApi\AccountProfile;
use DigiForgePrintifyApi\Runtime;

$root = getenv('DF_PRINTIFY_WP_FIXTURE_ROOT');
$socket = getenv('DF_PRINTIFY_WP_FIXTURE_SOCKET');
if (!is_string($root) || !str_starts_with($root,'/tmp/df-printify-')
    || !is_string($socket) || !str_starts_with($socket,'/tmp/df-printify-')) {
    throw new RuntimeException('Exact disposable workspace required');
}
define('DIGIFORGE_PRINTIFY_SYNTHETIC_JOURNEY',true);
require $root.'/wp-load.php';
if (wp_get_environment_type() !== 'local' || DB_HOST !== 'localhost:'.$socket || DB_NAME !== 'printify_fixture') {
    throw new RuntimeException('Disposable socket database required');
}
wp_set_current_user(1);
add_filter('pre_http_request',static function(): never { throw new RuntimeException('Fixture HTTP forbidden'); },PHP_INT_MAX);
$nonce=wp_create_nonce('digiforge_printify_account_profile_save');
$a=['shop'=>'107','account'=>'fixture-account','integration'=>'profile-a','custody'=>''];
$b=['integration'=>'profile-b']+$a;
$expect=static function(bool $pass,string $message): void {
    if (!$pass) throw new RuntimeException($message);
};
$mode=$argv[1] ?? 'test';
if (in_array($mode,['change','restore','displace','probe'],true)) {
    if ($mode==='probe') {
        $pdo = new PDO('mysql:unix_socket='.$socket.';dbname=printify_fixture','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->beginTransaction();
        try {
            $pdo->query("SELECT option_value FROM wp_options WHERE option_name='digiforge_printify_account_profile_v1' FOR UPDATE NOWAIT");
            throw new RuntimeException('Profile row was not locked during workspace write');
        } catch (PDOException $error) {
            if ((int)($error->errorInfo[1] ?? 0)!==1205) throw $error;
        } finally {
            $pdo->rollBack();
        }
    } elseif ($mode==='displace') {
        $workspace=Runtime::syntheticWorkspace('107');
        $result=$workspace->execute($workspace->inspect()['revision'],hash('sha256','concurrent-workspace-write'),'SHOPS_READ',[]);
        $expect($result['state']==='SYNTHETIC_OBSERVED','Concurrent workspace write failed');
    } else {
        $expect(AccountProfile::save($b,$nonce)==='SAVED_LOCAL_METADATA','Competing profile write failed');
        if ($mode==='restore') {
            $expect(AccountProfile::save($a,$nonce)==='SAVED_LOCAL_METADATA','Competing profile restoration failed');
        }
    }
    return;
}
if ($mode==='reopen') {
    $workspace=Runtime::syntheticWorkspace('107');
    $retained=array_column($workspace->inspect()['audit'],'sync_receipt');
    $expect(count($retained)===2 && array_column(array_column($retained,'scope'),'integration')===['profile-a','profile-a']
        && AccountProfile::current()===$b, 'Reopen lost ordering, scope, or durable evidence');
    echo "Independent reopen PASS: exact profile-a receipts retained after switch to profile-b\n";
    return;
}
global $wpdb;
$wpdb->query('CREATE TABLE df_printify_fixture_workspace (
    scope_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    revision int unsigned NOT NULL, state_json mediumblob NOT NULL, seal char(64) NOT NULL
) ENGINE=InnoDB');
$workspace=Runtime::syntheticWorkspace('107');
$expect($workspace !== null && AccountProfile::save($a,$nonce)==='SAVED_LOCAL_METADATA','Fixture profile or workspace unavailable');
$worker=static function(string $action): void {
    $pipes=[];
    $process=proc_open(['php',__FILE__,$action],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start independent worker');
    $out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    if (proc_close($process)!==0) throw new RuntimeException($out);
};
$sync=static fn(int $revision,string $id,array $input=[]) => $workspace->execute($revision,hash('sha256',$id),
    'FIXTURE_READ_SYNC',$input+['kind'=>'orders','integration'=>'profile-a','selection'=>[]]);
foreach ([
    ['insert-before','change',false,false],
    ['insert-after','change',true,true],
    ['update-before','change',false,false],
    ['update-restore','restore',false,false],
    ['update-stale-workspace','displace',false,false],
    ['update-after','change',true,true],
] as [$id,$action,$after,$allowed]) {
    $expect(AccountProfile::save($a,$nonce)==='SAVED_LOCAL_METADATA','Could not reset fixture scope');
    $revision=$workspace->inspect()['revision'];
    $armed=false;
    $began=false;
    $committing=false;
    $probed=false;
    $filter=static function(string $sql) use (&$armed,&$began,&$committing,&$probed,$action,$after,$worker): string {
        $isWrite = preg_match('/^\s*(UPDATE|INSERT INTO)\s+df_printify_fixture_workspace\b/i',$sql)===1;
        if (strtoupper(trim($sql))==='START TRANSACTION') {
            $began=true;
            if (!$after && !$armed) {
                $armed=true;
                $worker($action);
            }
        }
        // The reviewed baseline used a bare workspace write.
        if ($isWrite && !$began && !$after && !$armed) {
            $armed=true;
            $worker($action);
        }
        if ($after && $isWrite && $began) {
            $worker('probe');
            $probed=true;
        }
        if (strtoupper(trim($sql))==='COMMIT') {
            $committing=true;
        }
        if ($after && $committing && !$armed && str_contains($sql,'SELECT revision,state_json,seal FROM')) {
            $armed=true;
            $worker($action);
        }
        return $sql;
    };
    add_filter('query',$filter,PHP_INT_MAX);
    try {
        $result=$sync($revision,$id);
    } finally {
        remove_filter('query',$filter,PHP_INT_MAX);
    }
    $inspect=$workspace->inspect();
    $expectedDelta=$allowed || $action==='displace' ? 1 : 0;
    $seen=in_array(hash('sha256',$id),array_column($inspect['audit'],'operation_id'),true);
    echo "$id worker=".($armed?'ran':'missing')." lock=".($probed?'held':'-')." result=".$result['state']
        ." reason=".($result['reason'] ?? 'NONE')." revision=".$inspect['revision']." receipt=".($seen?'present':'absent').PHP_EOL;
    $expect($armed && $inspect['revision']===$revision+$expectedDelta && $seen===$allowed
        && (!$allowed || ($probed && $result['state']==='OBSERVED' && AccountProfile::current()===$b))
        && ($allowed || ($result['state']==='DENIED' && !isset($result['sync_receipt']))),
        "$id did not order workspace receipt and profile write correctly");
}
$expect($sync(0,'explicit-stale')['reason']==='STALE_REVISION_OR_WORKSPACE_LIMIT','Expired workspace revision was admitted');
$expect(Runtime::syntheticWorkspace('108')===null && !Runtime::permitsWorkspaceAccount('107','wrong-account'),
    'Cross-shop or cross-account workspace admitted');
echo "Workspace commit ordering PASS: initial insert, update, ABA, stale revision and exact scope\n";
