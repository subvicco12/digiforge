<?php
declare(strict_types=1);
define('ABSPATH','/disposable/');
define('DIGIFORGE_PRINTIFY_DIRECT_GET_FIXTURE',true);
define('WP_HTTP_BLOCK_EXTERNAL',true);
define('DIGIFORGE_PRINTIFY_DIRECT_READ_SCOPE',['shop'=>'107','account'=>'fixture-account','integration'=>'fixture-read','custody'=>'custody-ref:fixture']);
define('DIGIFORGE_PRINTIFY_DIRECT_FIXTURE_PAT','fixture-test-only');
function has_filter(string $name): bool { return true; }
function wp_get_environment_type(): string { return $GLOBALS['environment']; }
function wp_json_encode(mixed $value,int $flags=0): string|false { return json_encode($value,$flags); }
function add_filter(string $tag,callable $callback,int $priority,int $args): void { $GLOBALS['intercept']=$callback; }
function remove_filter(string $tag,callable $callback,int $priority): void { unset($GLOBALS['intercept']); }
function wp_safe_remote_get(string $url,array $args): mixed { ++$GLOBALS['calls']; return ($GLOBALS['intercept'])(false,$args,$url); }
function wp_remote_retrieve_response_code(array $reply): int { return $reply['response']['code']; }
function wp_remote_retrieve_header(array $reply,string $header): string { return $reply['headers'][$header]??''; }
function wp_remote_retrieve_body(array $reply): string { return $reply['body']; }
function apply_filters(string $tag,mixed $value,mixed ...$args): mixed {
    return match ($tag) {
        'digiforge_printify_direct_fixture_gates'=>$GLOBALS['gates'],
        'digiforge_printify_direct_fixture_response'=>$GLOBALS['response'],
        default=>$value,
    };
}
class WP_Error { public function __construct(string $code) {} }
require $argv[1].'/ProtectedGet.php';
require $argv[1].'/DirectReadSecret.php';
require $argv[1].'/DirectGet.php';
use DigiForgePrintifyApi\ProtectedGet;
use DigiForgePrintifyApi\DirectGet;
function check(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
$GLOBALS['calls']=0;
$GLOBALS['environment']='production';
$GLOBALS['gates']=['stop_all'=>false,'external_lock'=>false];
$scope=DIGIFORGE_PRINTIFY_DIRECT_READ_SCOPE+['site'=>'wp-fixture','actor'=>1,'session'=>str_repeat('a',64),'revision'=>str_repeat('b',64)];
$request=ProtectedGet::request($scope,'SHOPS_READ',[]);
$charge=static fn()=>true;
check(DirectGet::execute($request,$charge)['state']==='BLOCKED' && $GLOBALS['calls']===0,'Production reached HTTP');
$GLOBALS['environment']='local';
$GLOBALS['gates']['stop_all']=true;
check(DirectGet::execute($request,$charge)['state']==='BLOCKED' && $GLOBALS['calls']===0,'STOP bypass');
$GLOBALS['gates']['stop_all']=false;
check(DirectGet::execute($request,static fn()=>false)['reason']==='LOCAL_BUDGET_OR_STORAGE_UNAVAILABLE','Quota bypass');
$wrong=$request;$wrong['path']='/v1/shops/108/orders.json';
check(DirectGet::execute($wrong,$charge)['state']==='BLOCKED' && $GLOBALS['calls']===0,'Route substitution');
$GLOBALS['response']=['response'=>['code'=>200],'headers'=>['content-type'=>'application/json'],
    'body'=>'[{"id":107,"title":"Fixture","sales_channel":"fixture"},{"id":108,"title":"Hidden","sales_channel":"fixture"}]'];
$result=DirectGet::execute($request,$charge);
check($result['state']==='OBSERVED' && count($result['data']['shops'])===1 && !isset($GLOBALS['intercept']),'No scoped observation');
$GLOBALS['response']['response']['code']=429;
$GLOBALS['response']['headers']['retry-after']='75';
$limited=DirectGet::execute($request,$charge);
check($limited['reason']==='PROVIDER_RATE_LIMITED' && $limited['retry_after_seconds']===75 && !$limited['retry_permitted'],
    'Missing bounded 429 state');
$GLOBALS['response']['response']['code']=302;
check(DirectGet::execute($request,$charge)['state']==='UNAVAILABLE' && $GLOBALS['calls']===3,'Redirect or retry accepted');
$GLOBALS['response']['response']['code']=200;
$GLOBALS['response']['body']='[{"id":107,"title":"fixture-test-only","sales_channel":"fixture"}]';
check(DirectGet::execute($request,$charge)['state']==='UNAVAILABLE','Credential echo exposed');
echo "Direct GET method, held production, STOP, scope, budget, 429 and redirect checks passed.\n";

$GLOBALS['response']['response']['code']=429;
foreach ([
    [gmdate('D, d M Y H:i:s',time()+120).' GMT', 118, 120],
    [gmdate('D, d M Y H:i:s',time()+7200).' GMT', 3600, 3600],
    [gmdate('D, d M Y H:i:s',time()-120).' GMT', 0, 0],
    ['Fri, 31 Feb 2027 12:00:00 GMT',null,null],
] as [$header,$minimum,$maximum]) {
    $GLOBALS['response']['headers']['retry-after']=$header;
    $beforeCount=$GLOBALS['calls'];
    $result=DirectGet::execute($request,$charge);
    $seconds=$result['retry_after_seconds'] ?? null;
    check($result['reason']==='PROVIDER_RATE_LIMITED' && !$result['retry_permitted']
        && $GLOBALS['calls']===$beforeCount+1,'Date-based 429 triggered retry or changed outcome');
    check($minimum===null ? $seconds===null : is_int($seconds) && $seconds>=$minimum && $seconds<=$maximum,
        'Unexpected date-based wait hint');
}
echo "HTTP-date Retry-After, expired, capped and invalid delays passed.\n";

foreach ([400=>'PROVIDER_BAD_REQUEST',401=>'PROVIDER_UNAUTHORIZED',403=>'PROVIDER_FORBIDDEN',
    404=>'PROVIDER_NOT_FOUND',409=>'PROVIDER_CONFLICT',422=>'PROVIDER_VALIDATION_DENIED',503=>'PROVIDER_SERVER_ERROR'] as $status=>$reason) {
    $GLOBALS['response']['response']['code']=$status;
    $GLOBALS['response']['body']='{"message":"fixture-test-only"}';
    $result=DirectGet::execute($request,$charge);
    check($result['reason']===$reason && $result['http_status']===$status && !$result['retry_permitted']
        && !str_contains(json_encode($result,JSON_THROW_ON_ERROR),'fixture-test-only'),'Provider rejection leaked or was retried');
}
echo "Seven HTTP rejection classes preserve status and redact response body.\n";
