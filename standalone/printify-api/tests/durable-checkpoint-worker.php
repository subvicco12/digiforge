<?php
declare(strict_types=1);

// Test-only process. Its only connection is the disposable MariaDB Unix socket.
$q = json_decode(stream_get_contents(STDIN), true, 12, JSON_THROW_ON_ERROR);
if (PHP_SAPI !== 'cli' || !preg_match('/^\/tmp\/df-[a-zA-Z0-9\/-]+\.sock$/D', $q['socket'])
    || !preg_match('/^issue1273_[a-z0-9_]+$/D', $q['database'])
    || !preg_match('/^df_checkpoint_[a-f0-9]{10}$/D', $q['replay'])
    || !preg_match('/^[a-z0-9_]+digiforge_native_held_journals$/D', $q['journal'])) {
    throw new RuntimeException('Disposable fixture required');
}
$db = new PDO('mysql:unix_socket='.$q['socket'].';dbname='.$q['database'], $q['user'], $q['password']);
$db->beginTransaction();
$head = $db->prepare('SELECT revision, head_hash FROM '.$q['journal'].' WHERE shop_key=? AND operation_id=? FOR UPDATE');
$head->execute([$q['shop'], $q['operation']]);
$current = $head->fetch(PDO::FETCH_ASSOC);
$result = 'DENIED';
if ($current && (int)$current['revision'] === $q['revision'] && $current['head_hash'] === $q['head']) {
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
$db->commit();
echo $result.PHP_EOL;
flush();
if ($q['command'] === 'park' && $result === 'RESERVED') {
    sleep(15);
}
