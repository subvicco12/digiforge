<?php
declare(strict_types=1);
define('ABSPATH','/disposable/');
define('DIGIFORGE_PRINTIFY_GET_HTTP_FIXTURE', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
function wp_get_environment_type(): string { return $GLOBALS['environment']; }
function wp_json_encode(mixed $value,int $flags=0): string|false { return json_encode($value,$flags); }
function add_filter(string $tag, callable $callback, int $priority, int $args): void { $GLOBALS['intercept'] = $callback; }
function remove_filter(string $tag, callable $callback, int $priority): void { unset($GLOBALS['intercept']); }
function wp_safe_remote_post(string $url, array $args): mixed {
    ++$GLOBALS['calls'];
    return ($GLOBALS['intercept'])(false,$args,$url);
}
function apply_filters(string $tag, mixed $previous,array $payload): array {
    if ($tag !== 'digiforge_printify_protected_get_http_fixture') { throw new RuntimeException('Wrong fixture'); }
    $GLOBALS['wire']=$payload;
    return $GLOBALS['mock'];
}
function wp_remote_retrieve_response_code(array $reply): int { return $reply['response']['code']; }
function wp_remote_retrieve_header(array $reply, string $header): string { return $reply['headers'][$header]; }
function wp_remote_retrieve_body(array $reply): string { return $reply['body']; }
class WP_Error { public function __construct(string $code) {} }

require $argv[1].'/ProtectedGetHttp.php';
use DigiForgePrintifyApi\ProtectedGetHttp;
$GLOBALS['calls']=0;
$GLOBALS['environment']='production';
if (ProtectedGetHttp::exchange([],str_repeat('a',32),str_repeat('b',64)) !== null || $GLOBALS['calls'] !== 0) {
    throw new RuntimeException('Production attempted HTTP');
}
$GLOBALS['environment']='local';
$GLOBALS['mock']=['response'=>['code'=>200],'headers'=>['content-type'=>'application/json'],'body'=>'{"receipt":{},"signature":"synthetic"}'];
$result=ProtectedGetHttp::exchange(['scope'=>['site'=>'fixture']],str_repeat('a',32),str_repeat('b',64));
if (($result['signature'] ?? null)!=='synthetic' || $GLOBALS['wire']['request']['scope']['site']!=='fixture'
    || $GLOBALS['wire']['workspace']!==str_repeat('b',64) || $GLOBALS['wire']['correlation']!==str_repeat('a',32) || isset($GLOBALS['intercept'])) {
    throw new RuntimeException('Synthetic HTTP exchange failed');
}
foreach ([['code'=>302,'type'=>'application/json','body'=>'{}'],['code'=>200,'type'=>'text/html','body'=>'{}'],
    ['code'=>200,'type'=>'application/json','body'=>str_repeat('x',524289)]] as $bad) {
    $GLOBALS['mock']=['response'=>['code'=>$bad['code']],'headers'=>['content-type'=>$bad['type']],'body'=>$bad['body']];
    if (ProtectedGetHttp::exchange([],str_repeat('a',32),str_repeat('b',64))!==null) { throw new RuntimeException('Unsafe reply accepted'); }
}
echo "Protected GET WordPress HTTP framing, production hold, redirect, response type and size passed.\n";
