<?php
declare(strict_types=1);

define('ABSPATH','/disposable/');
require $argv[1].'/HeldSignedEvidence.php';
use DigiForgePrintifyApi\HeldSignedEvidence;

$key=sodium_crypto_sign_seed_keypair(str_repeat('B',32));
$payload='{"operation":"inert-fixture"}';
$envelope=json_decode(file_get_contents(__DIR__.'/authority/signed-positive-vector.json'),true,8,JSON_THROW_ON_ERROR);
$manifest=$envelope['manifest'];
$manifest['payload_digest']=hash('sha256',$payload);
ksort($manifest,SORT_STRING);
$canonical=static fn(array $value):string=>json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
$signed=['key_id'=>$envelope['key_id'],'manifest'=>$manifest];
$wire=$canonical($signed+['signature'=>base64_encode(sodium_crypto_sign_detached($canonical($signed),sodium_crypto_sign_secretkey($key)))]);
$identity=array_diff_key($manifest,array_flip(['version','issuer','audience','phase','issued','expires']));
$verify=static fn(string $bytes,string $sender='fixture-sender',bool $revoked=false,int $now=1700000000,array $expected=[]) =>
    HeldSignedEvidence::matchesPayload($bytes,$wire,'fixture-v1','fixture-witness',$sender,
        sodium_crypto_sign_publickey($key),$revoked,$now,$expected+$identity);
if (!$verify($payload) || $verify($payload.' ') || $verify($payload,'other-sender')
    || $verify($payload,'fixture-sender',true) || $verify($payload,'fixture-sender',false,1700000030)
    || $verify($payload,'fixture-sender',false,1700000000,['sequence'=>$identity['sequence']+1])) {
    throw new RuntimeException('Held sender evidence binding failed');
}
echo "Signed inert payload evidence PASS: modified bytes, foreign sender, revocation, expiry and stale sequence denied\n";
