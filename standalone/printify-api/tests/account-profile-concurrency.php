<?php
declare(strict_types=1);

use DigiForgePrintifyApi\AccountProfile;

$root = getenv('DF_PRINTIFY_WP_FIXTURE_ROOT');
$socket = getenv('DF_PRINTIFY_WP_FIXTURE_SOCKET');
if (!is_string($root) || !str_starts_with($root,'/tmp/df-printify-')
    || !is_string($socket) || !str_starts_with($socket,'/tmp/df-printify-')) {
    throw new RuntimeException('Disposable fixture required');
}
require $root.'/wp-load.php';
if (wp_get_environment_type() !== 'local' || DB_HOST !== 'localhost:'.$socket || DB_NAME !== 'printify_fixture') {
    throw new RuntimeException('Disposable socket database required');
}
wp_set_current_user(1);
$nonce=wp_create_nonce('digiforge_printify_account_profile_save');
$profile = static fn(string $id): array => ['shop'=>'107','account'=>'fixture-account',
    'integration'=>'race-'.$id,'custody'=>'custody-ref:alias-'.$id];
$key = static fn(array $p): string => hash('sha256', implode("\n", [$p['shop'],$p['account'],$p['integration']]));
$winner=static function(string $mode,string $token) use ($profile,$key,$nonce): string {
    return $mode==='switch' ? AccountProfile::switchProfile($key($profile('b')),$token,$nonce)
        : AccountProfile::save($profile('b'),$nonce,$token);
};
if (($argv[1] ?? '') === 'worker') {
    echo $winner($argv[2],$argv[3]);
    return;
}
$expect = static function(bool $ok,string $reason): void {
    if (!$ok) throw new RuntimeException($reason);
};
if (($argv[1] ?? '') === 'reopen') {
    $expect(AccountProfile::current()===$profile('b'), 'Winning scope and alias did not persist');
    $expect(count(AccountProfile::diagnostics()['change_receipts'])>=1, 'Winning profile receipt missing');
    echo "Independent process reopen PASS\n";
    return;
}
foreach (['a','b','c','a'] as $name) {
    $expect(AccountProfile::save($profile($name),$nonce)==='SAVED_LOCAL_METADATA', 'Profile seed failed');
}
$token = static fn(): string => method_exists(AccountProfile::class,'revisionToken')
    ? AccountProfile::revisionToken()
    : hash_hmac('sha256',json_encode(AccountProfile::current(),JSON_THROW_ON_ERROR),wp_salt('auth'));
foreach (['save','switch'] as $mode) {
    $expected = $token();
    $child = null;
    $filter = static function(string $query) use (&$child,$mode,$expected): string {
        if ($child === null && preg_match('/^\s*UPDATE\b/i',$query) && str_contains($query,'digiforge_printify_account_profile_v1')) {
            $pipes=[];
            $process=proc_open(['php', __FILE__, 'worker', $mode, $expected],
                [1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('Could not start competing worker');
            $child=trim(stream_get_contents($pipes[1]));
            $errors=stream_get_contents($pipes[2]);
            fclose($pipes[1]);fclose($pipes[2]);
            if (proc_close($process)!==0) throw new RuntimeException($errors);
        }
        return $query;
    };
    add_filter('query',$filter,PHP_INT_MAX);
    try {
        $stale=$mode==='switch' ? AccountProfile::switchProfile($key($profile('c')),$expected,$nonce)
            : AccountProfile::save($profile('c'),$nonce,$expected);
    } finally {
        remove_filter('query',$filter,PHP_INT_MAX);
    }
    $expect($child==='SAVED_LOCAL_METADATA' && $stale==='PROFILE_STALE',
        "$mode two-writer reproduction: winner=$child delayed=$stale");
    $expect(AccountProfile::current()===$profile('b'), "$mode overwrote winning account selection");
    $expect(AccountProfile::switchProfile($key($profile('c')),$expected,$nonce)==='PROFILE_STALE','Stale token accepted');
    $expect(AccountProfile::switchProfile(str_repeat('f',64),$token(),$nonce)==='INVALID_OR_UNASSIGNED_SCOPE','Unknown profile selected');
    $expect(AccountProfile::save(['shop'=>'108']+$profile('c'),$nonce,$token())==='INVALID_OR_UNASSIGNED_SCOPE','Unassigned shop selected');
    $expect(AccountProfile::save($profile('a'),$nonce,$token())==='SAVED_LOCAL_METADATA','Could not restore race baseline');
    $expect($token()!==$expected,'ABA profile token repeated');
    echo "Two-writer $mode: winner committed, delayed writer denied, scope and custody preserved\n";
}
$expect(AccountProfile::save($profile('b'),$nonce,$token())==='SAVED_LOCAL_METADATA','Failed final fixture selection');
