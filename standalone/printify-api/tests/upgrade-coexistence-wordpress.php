<?php
declare(strict_types=1);

use DigiForgePrintifyApi\{AccountProfile,Runtime};

$root = getenv('DF_PRINTIFY_WP_FIXTURE_ROOT');
$socket = getenv('DF_PRINTIFY_WP_FIXTURE_SOCKET');
$phase = $argv[1] ?? null;
if (!is_string($root) || !str_starts_with($root,'/tmp/df-printify-')
    || !is_string($socket) || !str_starts_with($socket,'/tmp/df-printify-')
    || !in_array($phase,['seed','upgrade','rollback','reinstall'],true)) {
    throw new RuntimeException('Disposable fixture required');
}
require $root.'/wp-load.php';
if (DB_HOST !== 'localhost:'.$socket || DB_NAME !== 'printify_fixture' || wp_get_environment_type() !== 'local'
    || !WP_HTTP_BLOCK_EXTERNAL) {
    throw new RuntimeException('Disposable socket-only WordPress required');
}
require_once ABSPATH.'wp-admin/includes/plugin.php';
wp_set_current_user(1);
$networkAttempts = 0;
add_filter('pre_http_request',static function () use (&$networkAttempts): never {
    ++$networkAttempts;
    throw new RuntimeException('Unexpected HTTP attempt in upgrade fixture');
}, PHP_INT_MAX);
$standalone = 'digiforge-printify-api/digiforge-printify-api.php';
$incumbents = ['digiforge/digiforge.php','printify-for-woocommerce/printify.php'];
$reference = dirname($root).'/upgrade-baseline.json';
$check = static function (bool $condition,string $reason): void {
    if (!$condition) throw new RuntimeException($reason);
};

if ($phase === 'seed') {
    foreach ($incumbents as $plugin) {
        $check(activate_plugin($plugin)===null,'Incumbent activation failed: '.$plugin);
    }
    $profile = AccountProfile::current();
    $check(($profile['shop']??null)==='107','Installed baseline scope missing');
    $nonce = wp_create_nonce('digiforge_printify_account_profile_save');
    foreach (['custody-ref:rotated-fixture',$profile['custody']] as $alias) {
        $check(AccountProfile::save(['custody'=>$alias]+$profile,$nonce,AccountProfile::revisionToken())==='SAVED_LOCAL_METADATA',
            'Unable to create historical scoped configuration receipt');
    }
} else {
    $check(activate_plugin($standalone)===null,'Standalone activation failed');
}
$expected = [$standalone,...$incumbents];
$actual = get_option('active_plugins');
sort($expected);
sort($actual);
$check($expected===$actual && !class_exists('WooCommerce'),'Activation inventory differs from held coexistence profile');

global $wpdb;
$rows = $wpdb->get_results($wpdb->prepare("SELECT option_name,option_value FROM {$wpdb->options}
    WHERE option_name LIKE %s ORDER BY option_name",$wpdb->esc_like('digiforge_printify_').'%'), ARRAY_A);
$profileRow = array_column($rows,'option_value','option_name')['digiforge_printify_account_profile_v1'] ?? null;
$check(is_string($profileRow)&&str_contains($profileRow,'fixture-account')&&count($rows)>0,
    'Historical profile evidence was not persisted');
$native = Runtime::inspectNativeHeldOperation('107','fixture-account',null,[],[]);
$check(($native['provider_execution_authorized']??false)===false,'Missing native authority admitted execution');
$check($networkAttempts===0,'Activation or diagnostics attempted HTTP');
$evidence = ['standalone_options_sha256'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR)),
    'scope_revision'=>AccountProfile::revisionToken(),'inventory'=>$actual];
if ($phase === 'seed') {
    file_put_contents($reference,json_encode($evidence,JSON_THROW_ON_ERROR));
} else {
    $check(json_decode(file_get_contents($reference),true,8,JSON_THROW_ON_ERROR)===$evidence,
        'Upgrade or rollback changed scoped Standalone data or active inventory');
}
echo json_encode(['phase'=>$phase,'php'=>PHP_VERSION,'wordpress'=>get_bloginfo('version'),
    'plugin_version'=>get_plugin_data(WP_PLUGIN_DIR.'/'.$standalone)['Version'],'outbound_attempts'=>$networkAttempts,
    'native_dispatch_authorized'=>false]+$evidence,JSON_THROW_ON_ERROR).PHP_EOL;
if ($phase !== 'reinstall') deactivate_plugins($standalone);
