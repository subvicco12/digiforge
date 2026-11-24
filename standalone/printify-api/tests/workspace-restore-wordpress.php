<?php
declare(strict_types=1);

/** Exercise exact-row restore and damaged backups in the existing disposable workspace. */
$db = new PDO('mysql:unix_socket='.$socket.';dbname=printify_fixture', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$scope = hash('sha256', "107\nfixture-account");
$read = $db->prepare('SELECT revision,state_json,seal FROM df_printify_fixture_workspace WHERE scope_key=?');
$read->execute([$scope]);
$backup = $read->fetch(PDO::FETCH_ASSOC);
requireFixture(is_array($backup), 'Missing workspace backup row');
$write = $db->prepare('UPDATE df_printify_fixture_workspace SET revision=?,state_json=?,seal=? WHERE scope_key=?');
$restore = static function (array $row) use ($write, $scope): void {
    $write->execute([$row['revision'], $row['state_json'], $row['seal'], $scope]);
};
$before = $workspace->inspect();
$seal = static fn(string $key, string $bytes): string => hash_hmac(
    'sha256', "DF_SYNTHETIC_WORKSPACE_1\n".$key."\n".$backup['revision']."\n".$bytes, wp_salt('auth')
);
$incompatible = json_encode(['unsupported_version'=>2], JSON_THROW_ON_ERROR);
$decoded = json_decode($backup['state_json'], true, 24, JSON_THROW_ON_ERROR);
$decoded[0][6] = 'invalid-connection-state';
$invalidTransport = json_encode($decoded, JSON_THROW_ON_ERROR);
try {
    foreach ([
        'truncated JSON'=>['state_json'=>substr($backup['state_json'], 0, -12)],
        'incompatible signed structure'=>['state_json'=>$incompatible, 'seal'=>$seal($scope, $incompatible)],
        'incompatible signed transport'=>['state_json'=>$invalidTransport, 'seal'=>$seal($scope, $invalidTransport)],
        'signed under another account'=>['seal'=>$seal(hash('sha256', "107\nother-account"), $backup['state_json'])],
    ] as $label=>$damaged) {
        $restore($damaged + $backup);
        requireFixture($workspace->redactedAuditExport() === null, $label.' accepted for export');
        $rejected = false;
        try {
            $workspace->inspect();
        } catch (RuntimeException|InvalidArgumentException|JsonException) {
            $rejected = true;
        }
        requireFixture($rejected, $label.' accepted for operator inspection');
        $result = $workspace->execute((int)$backup['revision'], hash('sha256', $label), 'FIXTURE_PROPOSAL_REVIEW', []);
        requireFixture($result['state'] === 'UNKNOWN' && $result['reason'] === 'WORKSPACE_UNAVAILABLE'
            && $result['provider_execution_authorized'] === false && $result['retry_permitted'] === false,
            $label.' accepted for write or provider authority');
    }
} finally {
    $restore($backup);
}
requireFixture($workspace->inspect() === $before && $workspace->redactedAuditExport() === $freshExport,
    'Exact restore failed to preserve revision, proposals, UNKNOWN or custody receipts');
$original = $before['audit'][0]['operation_id'] ?? null;
requireFixture(is_string($original) && preg_match('/^[a-f0-9]{64}$/D', $original) === 1, 'Missing original receipt identity');
$result = $workspace->execute((int)$backup['revision'], $original, 'FIXTURE_PROPOSAL_REVIEW', []);
requireFixture($result['reason'] === 'OPERATION_ALREADY_RECORDED' && $result['retry_permitted'] === false,
    'Restored row lost duplicate exclusion');
$result = $workspace->execute((int)$backup['revision'] - 1, hash('sha256', 'restore-stale'), 'FIXTURE_PROPOSAL_REVIEW', []);
requireFixture($result['reason'] === 'STALE_REVISION_OR_WORKSPACE_LIMIT' && $result['provider_execution_authorized'] === false,
    'Restored row accepted a stale revision or granted provider authority');
echo "Workspace restore PASS: four invalid backups denied, exact diagnostic/custody/UNKNOWN state restored, duplicate and stale writes denied\n";
