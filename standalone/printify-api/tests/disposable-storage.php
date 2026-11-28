<?php
declare(strict_types=1);

/** Run only against a disposable socket-local WordPress/MariaDB fixture. */
$root = getenv('DF_PRINTIFY_WP_FIXTURE_ROOT');
$socket = getenv('DF_PRINTIFY_WP_FIXTURE_SOCKET');
$phase = $argv[1] ?? '';
if (!is_string($root) || !str_starts_with($root, '/tmp/df-printify-')
    || !is_string($socket) || !str_starts_with($socket, '/tmp/df-printify-')
    || !in_array($phase, ['write', 'reopen'], true)) {
    throw new RuntimeException('Disposable fixture paths required.');
}

require $root . '/wp-load.php';
require_once WP_PLUGIN_DIR . '/digiforge-printify-api/FixtureSnapshotStore.php';

use DigiForgePrintifyApi\FixtureSnapshotStore;

function requireFixture(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

requireFixture(wp_get_environment_type() === 'local', 'Local WordPress fixture required');
wp_set_current_user(1);
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$plugin = 'digiforge-printify-api/digiforge-printify-api.php';
if ($phase === 'write') {
    deactivate_plugins($plugin);
    requireFixture(!is_plugin_active($plugin), 'Fixture failed to deactivate the plugin');
    $before = wp_load_alloptions();
    requireFixture(activate_plugin($plugin) === null && is_plugin_active($plugin), 'Disposable activation failed');
    $after = wp_load_alloptions();
    unset($before['active_plugins'], $after['active_plugins']);
    requireFixture($before === $after, 'Activation changed unrelated autoloaded options');
}
$status = DigiForgePrintifyApi\Runtime::discover();
requireFixture(preg_match('/^ \* Version: ([0-9]+\.[0-9]+\.[0-9]+)$/m',(string)file_get_contents(WP_PLUGIN_DIR.'/'.$plugin),$version)===1,
    'Plugin header is missing a semantic version');
requireFixture($status['version'] === $version[1] && $status['stop_all'] && $status['external_lock']
    && !$status['automation'] && $status['release_hold'] && !$status['provider_execution_authorized'],
    'Execution holds did not survive activation or restart');
requireFixture(in_array('OFFLINE_PROVIDER_READ_REVIEW', $status['capabilities'], true), 'Routed capability missing');
if ($phase === 'write') {
    define('DIGIFORGE_PRINTIFY_SYNTHETIC_JOURNEY', true);
    $http = 0;
    $tripwire = static function () use (&$http): never {
        ++$http;
        throw new RuntimeException('Network prohibited in the fixture');
    };
    add_filter('pre_http_request', $tripwire, PHP_INT_MAX);
    $render = static function (): string {
        ob_start();
        DigiForgePrintifyApi\Runtime::panel();
        return ob_get_clean();
    };
    try {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        requireFixture(str_contains($render(), 'Run synthetic journey'), 'Synthetic form unavailable');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['digiforge_printify_run_synthetic'=>'1', 'digiforge_printify_synthetic_nonce'=>'0000000000'];
        requireFixture(str_contains($render(), 'could not be verified'), 'Invalid nonce accepted');
        $_POST['digiforge_printify_synthetic_nonce'] = wp_create_nonce('digiforge_printify_synthetic_journey_v1');
        $panel = $render();
        requireFixture(str_contains($panel, 'Synthetic journey passed.')
            && str_contains($panel, 'SYNTHETIC_RECONCILIATION') && !str_contains($panel, 'fixture@example.test'),
            'Authenticated synthetic panel did not complete or leaked fixture details');
        wp_set_current_user(0);
        requireFixture($render() === '', 'Revoked operator retained synthetic form');
        wp_set_current_user(1);
        requireFixture($http === 0, 'Unexpected external attempt during synthetic panel run');
    } finally {
        $_POST = [];
        unset($_SERVER['REQUEST_METHOD']);
        remove_filter('pre_http_request', $tripwire, PHP_INT_MAX);
    }
}
$order = '{"id":"fixture-order","shop_id":107,"status":"pending","line_items":[]}';
$orderInput = ['order_id'=>'fixture-order'];
$review = DigiForgePrintifyApi\Runtime::reviewProviderRead('107', 'fixture-account', 'ORDER_READ', $orderInput, $order);
requireFixture($review['state'] === 'OFFLINE_PROVIDER_READ_REVIEWED' && $review['native_reconciliation'] === 'BLOCKED'
    && !$review['provider_outcome_confirmed'] && !$review['retry_permitted'], 'Order fixture misclassified');
requireFixture(DigiForgePrintifyApi\Runtime::reviewProviderRead('108', 'fixture-account', 'ORDER_READ', $orderInput, $order)['state'] === 'DENIED',
    'Wrong shop reached the provider router');
$db = new PDO('mysql:unix_socket=' . $socket . ';dbname=printify_fixture', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$body = '{"id":42}';
$id = hash('sha256', 'snapshot-fixture');
if ($phase === 'write') {
    $db->exec('CREATE TABLE df_printify_fixture_snapshots (
        snapshot_id char(64) NOT NULL PRIMARY KEY,
        shop_id varchar(19) NOT NULL,
        account_reference varchar(100) NOT NULL,
        source_identity varchar(32) NOT NULL,
        endpoint_name varchar(255) NOT NULL,
        content_sha256 char(64) NOT NULL,
        snapshot_body mediumblob NOT NULL,
        observed_at bigint unsigned NOT NULL,
        evidence_state varchar(32) NOT NULL,
        attestation varbinary(4096) NULL,
        signature varbinary(88) NULL
    ) ENGINE=InnoDB');
}
$store = new FixtureSnapshotStore($db, '107', 'fixture-account');
$keys = sodium_crypto_sign_seed_keypair(str_repeat('T', SODIUM_CRYPTO_SIGN_SEEDBYTES));
define('DIGIFORGE_PRINTIFY_FIXTURE_EVIDENCE_KEY', base64_encode(sodium_crypto_sign_publickey($keys)));
define('DIGIFORGE_PRINTIFY_FIXTURE_SOURCE', str_repeat('a', 40));
define('DIGIFORGE_PRINTIFY_FIXTURE_REVISION', 2);
$request = ['kind'=>'blueprint','blueprint_id'=>42];
$result = DigiForgePrintifyApi\Runtime::validateCatalog('107', $request, $body);
$evidence = ['schema'=>'DF_PRINTIFY_SNAPSHOT_EVIDENCE_2','evidence_class'=>'SIGNED_SYNTHETIC',
    'account_reference'=>'fixture-account','shop'=>'107','request_sha256'=>$result['request_sha256'],
    'content_sha256'=>hash('sha256',$body),'source_sha'=>str_repeat('a',40),'source_revision'=>2,
    'operation_id'=>hash('sha256','signed-operation'),'observed_at'=>1700000000,'expires_at'=>1700000060];
$expected = array_diff_key($evidence, array_flip(['schema','evidence_class','content_sha256','observed_at','expires_at']));
$sign = static function (array $record) use ($keys): array {
    $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return [$json, base64_encode(sodium_crypto_sign_detached("DF_PRINTIFY_SNAPSHOT_EVIDENCE_2\n" . $json,
        sodium_crypto_sign_secretkey($keys)))];
};
[$json, $signature] = $sign($evidence);
if ($phase === 'write') {
    requireFixture($store->appendAuthenticated($json, $signature, $request, $body, $expected, 1700000001), 'Signed insert failed');
    requireFixture(!$store->appendAuthenticated($json, $signature, $request, $body, $expected, 1700000001), 'Signed replay admitted');
    foreach (['evidence_class'=>'PROVIDER_CONFIRMED','source_revision'=>1,'shop'=>'108','operation_id'=>str_repeat('b',64)] as $key=>$value) {
        [$wrong, $validSignature] = $sign(array_replace($evidence, [$key=>$value]));
        $wrongContext = $key === 'source_revision' ? array_replace($expected, [$key=>$value]) : $expected;
        requireFixture(!$store->appendAuthenticated($wrong, $validSignature, $request, $body, $wrongContext, 1700000001), 'Substitution admitted');
    }
    requireFixture(!$store->appendAuthenticated($json, str_repeat('A',88), $request, $body, $expected, 1700000001), 'Forged signature admitted');
    requireFixture(!$store->appendAuthenticated($json, $signature, $request, '{"id":43}', $expected, 1700000001), 'Changed content admitted');
    $request = ['kind'=>'blueprint','blueprint_id'=>42];
    requireFixture($store->append($id, $request, $body, 1700000000), 'Append failed');
    requireFixture(!$store->append($id, $request, $body, 1700000001), 'Duplicate append succeeded');
}
if ($phase === 'write') {
    $list = ['kind'=>'variants','blueprint_id'=>42,'provider_id'=>7];
    $variant = ['variant_id'=>9] + array_replace($list, ['kind'=>'variant']);
    $variantsBody = '{"variants":[{"id":9},{"id":10}]}';
    $listResult = DigiForgePrintifyApi\Runtime::validateCatalog('107', $list, $variantsBody);
    $variantResult = DigiForgePrintifyApi\Runtime::validateCatalog('107', $variant, $variantsBody);
    requireFixture($listResult['endpoint'] === $variantResult['endpoint'], 'Regression requires shared endpoint');
    $listEvidence = array_replace($evidence, ['request_sha256'=>$listResult['request_sha256'],
        'content_sha256'=>hash('sha256', $variantsBody), 'operation_id'=>hash('sha256', 'list-operation')]);
    $listExpected = array_replace($expected, array_intersect_key($listEvidence, $expected));
    [$listJson, $listProof] = $sign($listEvidence);
    requireFixture(!$store->appendAuthenticated($listJson, $listProof, $variant, $variantsBody, $listExpected, 1700000001),
        'Signed variant list substituted for exact variant request');
    requireFixture($store->appendAuthenticated($listJson, $listProof, array_reverse($list, true), $variantsBody, $listExpected, 1700000001),
        'Exact selector with reordered keys denied');
    requireFixture($store->readAuthenticated($variant, $listExpected, 1700000001) === null,
        'Signed variant list substituted on readback');
}
$signed = $store->readAuthenticated($request, $expected, 1700000001);
requireFixture($signed !== null && $signed['provider_outcome_confirmed'] === false
    && $signed['native_operation_mutated'] === false && $signed['retry_permitted'] === false
    && $signed['provider_execution_authorized'] === false, 'Authenticated readback missing or authorizing');
requireFixture($store->read($signed['snapshot_id']) === null, 'Unsigned read exposed signed row');
requireFixture($store->readAuthenticated($request, $expected, 1700000060) === null, 'Expired proof admitted');
requireFixture($store->readAuthenticated($request, array_replace($expected, ['request_sha256'=>str_repeat('0',64)]), 1700000001) === null, 'Wrong request admitted');
$row = $store->read($id);
requireFixture($row !== null && $row['source_identity'] === 'SYNTHETIC_PRINTIFY'
    && $row['evidence_state'] === 'FRESHNESS_UNVERIFIED'
    && $row['content_sha256'] === hash('sha256', $body)
    && (int)$row['observed_at'] === 1700000000 && !array_key_exists('snapshot_body', $row), 'Stored observation mismatch');
foreach ([['108','fixture-account'],['107','other-account']] as [$shop,$account]) {
    try {
        new FixtureSnapshotStore($db, $shop, $account);
        throw new RuntimeException('Wrong scope admitted');
    } catch (InvalidArgumentException) {
        // Exact configured scope required.
    }
}
wp_set_current_user(0);
requireFixture($store->read($id) === null, 'Revoked operator context retained access');
requireFixture($store->readAuthenticated($request, $expected, 1700000001) === null, 'Revoked operator retained signed read permission');
wp_set_current_user(1);
require __DIR__ . "/custody-mock-acceptance.php";
if ($phase==='write') {
    require __DIR__ . "/operational-sprint-acceptance.php";
}
require __DIR__ . "/disposable-workspace.php";
echo "Disposable snapshot $phase PASS\n";
