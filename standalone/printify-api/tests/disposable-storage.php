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
        evidence_state varchar(32) NOT NULL
    ) ENGINE=InnoDB');
}
$store = new FixtureSnapshotStore($db, '107', 'fixture-account');
if ($phase === 'write') {
    $request = ['kind'=>'blueprint','blueprint_id'=>42];
    requireFixture($store->append($id, $request, $body, 1700000000), 'Append failed');
    requireFixture(!$store->append($id, $request, $body, 1700000001), 'Duplicate append succeeded');
}
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
echo "Disposable snapshot $phase PASS\n";
