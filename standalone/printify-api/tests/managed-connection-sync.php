<?php
declare(strict_types=1);
namespace DigiForgePrintifyApi {
    function fsync($handle): bool {
        if ((fstat($handle)['mode'] & 0170000) === 0040000 && ++$GLOBALS['dir_syncs'] === $GLOBALS['fail_sync']) return false;
        return \fsync($handle);
    }
}
namespace {
    define('ABSPATH', __DIR__);
    $_SERVER['DOCUMENT_ROOT']=__DIR__;
    $dir=sys_get_temp_dir().'/df-sync-'.bin2hex(random_bytes(8));
    mkdir($dir,0700);
    define('DIGIFORGE_PRINTIFY_MANAGED_DIR',$dir);
    require ($argv[1]??__DIR__.'/../digiforge-printify-api').'/ManagedConnection.php';
    $class=DigiForgePrintifyApi\ManagedConnection::class;
    $scope=['shop'=>'28930593','account'=>'fixture','integration'=>'read','custody'=>'custody-ref:fixture'];
    $verify=static function(bool $condition): void {
        if (!$condition) throw new RuntimeException('Uncertain persistence assertion failed');
    };
    try {
        $GLOBALS['dir_syncs']=0;
        $GLOBALS['fail_sync']=2;
        $verify($class::save($scope,'synthetic-token-first')==='CREDENTIAL_SAVE_UNCERTAIN');
        $verify(is_file($dir.'/credential.json'));
        $verify($class::state($scope)==='CREDENTIAL_SAVE_UNCERTAIN');
        $token=new ReflectionMethod($class,'token');
        $verify($token->invoke(null,$dir,$scope)===null);
        $verify($class::save($scope,'synthetic-token-second')==='CREDENTIAL_SAVE_UNCERTAIN');
        $GLOBALS['fail_sync']=0;
        $verify($class::remove());
        $verify(!is_file($dir.'/credential.json') && $class::state($scope)==='CREDENTIAL_MISSING_OR_INVALID');
        $verify($class::save($scope,'synthetic-token-second')==='CREDENTIAL_SAVED_HELD');
        $verify($class::state($scope)==='CREDENTIAL_HELD');
        echo "PASS injected post-rename sync failure leaves durable quarantine, denies token resolution and replacement, then disconnect cleans up\n";
    } finally {
        foreach (glob($dir.'/*')?:[] as $file) unlink($file);
        rmdir($dir);
    }
}
