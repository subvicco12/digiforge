<?php
declare(strict_types=1);
define('ABSPATH', __DIR__);
$_SERVER['DOCUMENT_ROOT']=__DIR__;
$dir=$argv[2]??sys_get_temp_dir().'/df-managed-'.bin2hex(random_bytes(8));
mkdir($dir,0700);
define('DIGIFORGE_PRINTIFY_MANAGED_DIR',$dir);
require ($argv[1]??__DIR__.'/../digiforge-printify-api').'/ManagedConnection.php';
use DigiForgePrintifyApi\ManagedConnection;
function check(bool $ok): void { if (!$ok) throw new RuntimeException('Managed connection assertion failed'); }
try {
    $scope=['shop'=>'107','account'=>'fixture-account','integration'=>'fixture-read','custody'=>'custody-ref:fixture'];
    check(ManagedConnection::save($scope,'fixture-managed-token-01')==='CREDENTIAL_SAVED_HELD');
    check((fileperms($dir.'/credential.json')&0777)===0600);
    clearstatcache();
    $inode=fileinode($dir.'/credential.json');
    check(ManagedConnection::save($scope,'fixture-managed-token-02')==='CREDENTIAL_SAVED_HELD');
    clearstatcache();
    check(fileinode($dir.'/credential.json')!==$inode);
    check(ManagedConnection::save($scope,"bad\nvalue")==='CREDENTIAL_SAVE_FAILED');
    check(ManagedConnection::save($scope,'fixture-managed-token-03',static fn()=>false)==='CREDENTIAL_SAVE_FAILED');
    check(!ManagedConnection::remove(static fn()=>false));
    check(ManagedConnection::state($scope)==='CREDENTIAL_HELD');
    check(ManagedConnection::remove());
    check(!file_exists($dir.'/credential.json'));
    check(ManagedConnection::state($scope)==='CREDENTIAL_MISSING_OR_INVALID');
    echo "Managed credential storage, permissions, malformed input and cleanup PASS\n";
} finally {
    foreach (glob($dir.'/*')?:[] as $file) unlink($file);
    rmdir($dir);
}
