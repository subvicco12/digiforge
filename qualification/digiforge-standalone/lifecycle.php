<?php
declare(strict_types=1);

use DigiForgePrintifyApi\Runtime;

$phase = $argv[1];
$root = getenv('DF_PRINTIFY_WP_FIXTURE_ROOT');
$socket = getenv('DF_PRINTIFY_WP_FIXTURE_SOCKET');
function check(bool $ok, string $reason): void {
    if (!$ok) throw new RuntimeException($reason);
}
check(PHP_SAPI === 'cli' && str_starts_with($root,'/tmp/df-printify-')
    && str_starts_with($socket,'/tmp/df-printify-'),'Isolated fixture required');
if ($phase === 'install') define('WP_INSTALLING',true);
$_SERVER['HTTP_HOST']='127.0.0.1:18276';
require $root.'/wp-load.php';
check(DB_HOST === 'localhost:'.$socket && DB_NAME === 'printify_fixture'
    && WP_HTTP_BLOCK_EXTERNAL && !defined('WP_ACCESSIBLE_HOSTS') && wp_get_environment_type()==='local','Unsafe runtime');
$http=0;
add_filter('pre_http_request',static function () use (&$http): WP_Error {
    ++$http;
    return new WP_Error('acceptance_egress_denied');
}, PHP_INT_MAX);
add_filter('pre_wp_mail',static fn()=>true);
require_once ABSPATH.'wp-admin/includes/plugin.php';
$standalone='digiforge-printify-api/digiforge-printify-api.php';
if ($phase === 'install') {
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    wp_install('Disposable coexistence','admin','admin@example.invalid',false,'','local-fixture-only-123');
    wp_set_current_user(1);
    get_user_by('id',1)->add_cap('digiforge_printify_review_shop_107');
    check(activate_plugin($standalone)===null && get_option('active_plugins')===[$standalone],'Standalone activation failed');
} else {
    wp_set_current_user(1);
    $incumbents=[];
    foreach (get_plugins() as $file=>$info) {
        check(!str_starts_with($file,'woocommerce/') && strcasecmp($info['Name'],'WooCommerce')!==0,'WooCommerce present');
        if (str_starts_with($file,'digiforge/') && $info['Version']==='1.0.103.25.2') $incumbents['digiforge']=$file;
        if ($info['Version']==='3.2' && str_contains(strtolower($info['Name']),'printify')) $incumbents['shipping']=$file;
    }
    check(count($incumbents)===2,'Expected incumbent plugin headers missing');
    if ($phase === 'incumbents') {
        foreach ($incumbents as $plugin) check(activate_plugin($plugin)===null,'Incumbent activation failed');
    } elseif ($phase === 'deactivate') {
        deactivate_plugins($standalone);
    } elseif ($phase === 'reactivate') {
        check(activate_plugin($standalone)===null,'Standalone reactivation failed');
    } elseif ($phase === 'unknown-write' || $phase === 'unknown-reopen') {
        global $wpdb;
        define('DIGIFORGE_PRINTIFY_SYNTHETIC_JOURNEY', true);
        if ($phase === 'unknown-write') {
            check($wpdb->query('CREATE TABLE df_printify_fixture_workspace (
                scope_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
                revision int unsigned NOT NULL, state_json mediumblob NOT NULL, seal char(64) NOT NULL
            ) ENGINE=InnoDB')!==false,'Fixture workspace schema failed');
        }
        $workspace=Runtime::syntheticWorkspace('107');
        check($workspace!==null,'Fixture workspace unavailable');
        $id=hash('sha256','coexistence-lost-ack');
        if ($phase === 'unknown-write') {
            $db=new PDO('mysql:unix_socket='.$socket.';dbname=printify_fixture','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $initial=$workspace->execute(0,hash('sha256','coexistence-initial'),'SHOPS_READ',[]);
            check($initial['state']==='SYNTHETIC_OBSERVED','Workspace initialization failed');
            $inject=static function (string $sql) use ($db): string {
                if (!str_starts_with($sql,'UPDATE df_printify_fixture_workspace SET revision=')) return $sql;
                $db->exec($sql);
                return 'SELECT * FROM df_missing_fixture_ack';
            };
            add_filter('query',$inject);
            $prior=$wpdb->suppress_errors(true);
            try {
                $lost=$workspace->execute(1,$id,'ORDERS_READ',[]);
                check($lost['state']==='UNKNOWN' && !$lost['retry_permitted'],'Lost acknowledgment promoted');
            } finally {
                remove_filter('query',$inject);
                $wpdb->suppress_errors($prior);
            }
        }
        check($workspace->execute(2,$id,'ORDERS_READ',[])['reason']==='OPERATION_ALREADY_RECORDED','UNKNOWN identity became replayable');
    } else {
        throw new RuntimeException('Unknown lifecycle phase');
    }
    foreach ($incumbents as $plugin) check(is_plugin_active($plugin),'Incumbent activation drift');
    if ($phase === 'deactivate') check(!is_plugin_active($standalone),'Standalone deactivation failed');
}
check($http===0,'Unintercepted provider/network attempt in activation or lifecycle phase');
echo json_encode(['phase'=>$phase,'active'=>get_option('active_plugins'),'http_attempts'=>$http],JSON_THROW_ON_ERROR).PHP_EOL;
