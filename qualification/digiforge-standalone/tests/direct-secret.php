<?php
declare(strict_types=1);
use DigiForgePrintifyApi\DirectReadSecret;
use DigiForgePrintifyApi\DirectGet;
use DigiForgePrintifyApi\ProtectedGet;

function wp_json_encode(mixed $value, int $flags=0): string|false { return json_encode($value,$flags); }
function wp_safe_remote_get(string $url,array $args): array
{
    check($url==='https://api.printify.com/v1/shops.json','Wrong origin or route');
    check($args['method']==='GET' && $args['sslverify'] && $args['reject_unsafe_urls']
        && $args['redirection']===0 && $args['timeout']===5 && $args['limit_response_size']===262144,'Unsafe HTTP settings');
    check($args['headers']['Authorization']==='Bearer '.$GLOBALS['cfg']['token'],'Wrong credential supplied');
    ++$GLOBALS['calls'];
    if ($GLOBALS['revoke_in_flight']) unlink(DIGIFORGE_PRINTIFY_DIRECT_CONFIG_FILE);
    return ['code'=>200,'body'=>'[{"id":107,"title":"Synthetic shop","sales_channel":"fixture"},{"id":108,"title":"Other","sales_channel":"fixture"}]'];
}
function wp_remote_retrieve_response_code(array $reply): int { return $reply['code']; }
function wp_remote_retrieve_header(array $reply,string $header): string { return $header==='content-type' ? 'application/json' : ''; }
function wp_remote_retrieve_body(array $reply): string { return $reply['body']; }
$root=sys_get_temp_dir().'/df-secret-'.bin2hex(random_bytes(8));
mkdir($root,0700);
mkdir($root.'/web',0700);
define('ABSPATH',$root.'/web/');
$_SERVER['DOCUMENT_ROOT']=$root.'/web';
define('DIGIFORGE_PRINTIFY_DIRECT_CONFIG_FILE',$root.'/direct.json');
require $argv[1].'/DirectReadSecret.php';
require $argv[1].'/ProtectedGet.php';
require $argv[1].'/DirectGet.php';
function check(bool $ok,string $error): void { if (!$ok) throw new RuntimeException($error); }
$cfg=['schema'=>1,'scope'=>['shop'=>'107','account'=>'fixture-account','integration'=>'fixture-read','custody'=>'custody-ref:fixture'],
    'token'=>'synthetic-not-a-provider-token','state'=>'approved-read-only','expires_at'=>time()+60,
    'stop_all'=>false,'external_lock'=>false,'release_hold'=>false];
$file=DIGIFORGE_PRINTIFY_DIRECT_CONFIG_FILE;
try {
    check(DirectReadSecret::read()===null,'Missing credential allowed');
    file_put_contents($file,json_encode($cfg,JSON_THROW_ON_ERROR));
    chmod($file,0644);
    check(DirectReadSecret::read()===null,'Unsafe permission allowed');
    chmod($file,0600);
    check(DirectReadSecret::read()=== $cfg,'Secure file rejected');
    $cfg['token']='synthetic-rotated-credential';
    file_put_contents($file.'.next',json_encode($cfg,JSON_THROW_ON_ERROR));
    chmod($file.'.next',0600);
    rename($file.'.next',$file);
    check(DirectReadSecret::read()=== $cfg,'Rotated config not read');
    $GLOBALS['calls']=0;
    $GLOBALS['revoke_in_flight']=false;
    $scope=$cfg['scope']+['site'=>'wp-fixture','actor'=>1,'session'=>str_repeat('a',64),'revision'=>str_repeat('b',64)];
    $request=ProtectedGet::request($scope,'SHOPS_READ',[]);
    foreach (['stop_all','external_lock','release_hold'] as $gate) {
        $held=$cfg;
        $held[$gate]=true;
        file_put_contents($file,json_encode($held,JSON_THROW_ON_ERROR));
        check(DirectGet::execute($request,static fn()=>true)['state']==='BLOCKED','Hold bypassed');
    }
    file_put_contents($file,json_encode($cfg,JSON_THROW_ON_ERROR));
    $wrong=ProtectedGet::request(['shop'=>'108']+$scope,'SHOPS_READ',[]);
    check(DirectGet::execute($wrong,static fn()=>true)['state']==='BLOCKED','Cross-shop read allowed');
    check(DirectGet::execute($request,static fn()=>false)['reason']==='LOCAL_BUDGET_OR_STORAGE_UNAVAILABLE','Quota bypassed');
    check($GLOBALS['calls']===0,'A denied request reached transport');
    $result=DirectGet::execute($request,static fn()=>true);
    check($result['state']==='OBSERVED' && $result['source']==='PRINTIFY_HTTPS_GET'
        && count($result['data']['shops'])===1 && !str_contains(json_encode($result),$cfg['token']),'Scoped redacted observation missing');
    $GLOBALS['revoke_in_flight']=true;
    check(DirectGet::execute($request,static fn()=>true)['state']==='BLOCKED' && $GLOBALS['calls']===2,'Released result after removal');
    touch($file);
    chmod($file,0600);
    foreach (['revoked','unreviewed'] as $state) {
        $cfg['state']=$state;
        file_put_contents($file,json_encode($cfg,JSON_THROW_ON_ERROR));
        check(DirectReadSecret::read()===null,'Revoked or unreviewed credential allowed');
    }
    unlink($file);
    symlink($root.'/web/secret.json',$file);
    file_put_contents($root.'/web/secret.json',json_encode($cfg,JSON_THROW_ON_ERROR));
    check(DirectReadSecret::read()===null,'Web-root symlink allowed');
    unlink($file);
    check(DirectReadSecret::read()===null,'Removed config still admitted');
    echo "Secret resolver denies missing, unsafe, revoked and web-root paths; rotation and removal verified.\n";
} finally {
    @unlink($file);
    @unlink($root.'/web/secret.json');
    rmdir($root.'/web'); rmdir($root);
}
