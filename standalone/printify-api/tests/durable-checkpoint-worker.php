<?php
declare(strict_types=1);

// Test-only process. Its only connection is the disposable MariaDB Unix socket.
$q = json_decode(stream_get_contents(STDIN), true, 12, JSON_THROW_ON_ERROR);
if (PHP_SAPI !== 'cli' || !preg_match('/^\/tmp\/df-[a-zA-Z0-9\/-]+\.sock$/D', $q['socket'])
    || !preg_match('/^issue1273_[a-z0-9_]+$/D', $q['database'])
    || !preg_match('/^df_checkpoint_[a-f0-9]{10}$/D', $q['replay'])
    || !preg_match('/^[a-z0-9_]+usermeta$/D', $q['usermeta'])
    || !preg_match('/^df_authority_[a-f0-9]{10}$/D', $q['authority'])
    || !preg_match('/^[a-z0-9_]+digiforge_native_held_journals$/D', $q['journal'])) {
    throw new RuntimeException('Disposable fixture required');
}
$db = new PDO('mysql:unix_socket='.$q['socket'].';dbname='.$q['database'], $q['user'], $q['password']);
$db->beginTransaction();
// Revocation writers update this same InnoDB row. Hold its lock through commit.
$authority = $db->prepare('SELECT * FROM '.$q['authority'].' WHERE operation_id=? FOR UPDATE');
$authority->execute([$q['operation']]);
$grant = $authority->fetch(PDO::FETCH_ASSOC);
$sessions = $db->prepare('SELECT meta_value FROM '.$q['usermeta']." WHERE user_id=? AND meta_key='session_tokens' FOR UPDATE");
$sessions->execute([$q['actor']]);
$stored = $sessions->fetchAll(PDO::FETCH_COLUMN);
$tokens = count($stored) === 1 ? unserialize($stored[0], ['allowed_classes'=>false]) : false;
$expiration = is_array($tokens) ? ($tokens[$q['session']]['expiration'] ?? null) : null;
if (!is_int($expiration) || $expiration <= time()
    || !$grant
    || $grant['identity_digest'] !== $q['digest']
    || (int)$grant['actor_id'] !== $q['actor']
    || $grant['session_hash'] !== $q['session']
    || (int)$grant['authorization_revision'] !== $q['authorization_revision']
    || (int)$grant['generation'] !== $q['generation']
    || (int)$grant['generation_floor'] !== $q['generation']
    || $grant['witness_hash'] !== $q['witness_hash']
    || $grant['anchor_hash'] !== $q['anchor_hash']
    || (int)$grant['expires_at'] <= $q['tick']
    || (int)$grant['revoked'] !== 0
    || (int)$grant['witness_revoked'] !== 0) {
    $db->rollBack();
    echo "DENIED\n";
    exit;
}
$head = $db->prepare('SELECT revision, head_hash, actor_id FROM '.$q['journal'].' WHERE shop_key=? AND operation_id=? FOR UPDATE');
$head->execute([$q['shop'], $q['operation']]);
$current = $head->fetch(PDO::FETCH_ASSOC);
$result = 'DENIED';
if ($current && (int)$current['actor_id'] === $q['actor'] && (int)$current['revision'] === $q['revision'] && $current['head_hash'] === $q['head']) {
    if ($q['command'] === 'finalize') {
        $change = $db->prepare("UPDATE ".$q['replay']." SET outcome='UNKNOWN' WHERE operation_id=? AND identity_digest=? AND owner=? AND expires_at>? AND outcome='RESERVED'");
        $change->execute([$q['operation'], $q['digest'], $q['owner'], $q['tick']]);
        if ($change->rowCount() === 1) { $result = 'UNKNOWN'; }
    } else {
        $existing = $db->prepare('SELECT 1 FROM '.$q['replay'].' WHERE operation_id=? FOR UPDATE');
        $existing->execute([$q['operation']]);
        if (!$existing->fetchColumn()) {
            $insert = $db->prepare('INSERT INTO '.$q['replay'].' (operation_id, identity_digest, owner, expires_at) VALUES (?,?,?,?)');
            $insert->execute([$q['operation'], $q['digest'], $q['owner'], $q['tick'] + 10]);
            $result = 'RESERVED';
        }
    }
}
if (isset($q['barrier']) && $result === 'RESERVED') {
    if (!preg_match('/^\/tmp\/df-writer-[a-zA-Z0-9]+$/D', $q['barrier'])) {
        throw new RuntimeException('Disposable barrier required');
    }
    echo "LOCKED\n";
    flush();
    $deadline = hrtime(true) + 5_000_000_000;
    while (file_get_contents($q['barrier']) !== 'release') {
        if (hrtime(true) >= $deadline) {
            $db->rollBack();
            echo "DENIED\n";
            exit;
        }
        usleep(10000);
    }
}
$db->commit();
echo $result.PHP_EOL;
flush();
if ($q['command'] === 'park' && $result === 'RESERVED') {
    sleep(15);
}
