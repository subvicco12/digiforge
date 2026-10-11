<?php
declare(strict_types=1);
/** Calls registered local handlers with genuine fixture-user nonces; intercepts return and forbids HTTP. */
final class PortalActionAcceptanceFixture {
 public static function invoke(string $constant,array $body,bool $error=false):array {
  $portal=new DigiForge\Portal\Portal();$portal->register();$action=(new ReflectionClass($portal))->getConstant($constant);$post=$_POST;$request=$_REQUEST;$redirect='';$calls=0;
  $capture=static function(string $url)use(&$redirect):never{$redirect=$url;throw new RuntimeException('local-portal-return');};$http=static function($pre)use(&$calls){$calls++;return new WP_Error('offline_portal','External requests forbidden.');};add_filter('wp_redirect',$capture);add_filter('pre_http_request',$http);
  try{$_POST=$_REQUEST=$body+['_wpnonce'=>wp_create_nonce($action)];try{do_action('admin_post_'.$action);PHPUnit\Framework\Assert::fail('Registered portal action must return.');}catch(RuntimeException $e){PHPUnit\Framework\Assert::assertSame('local-portal-return',$e->getMessage());}}finally{remove_filter('wp_redirect',$capture);remove_filter('pre_http_request',$http);$_POST=$post;$_REQUEST=$request;}
  parse_str((string)parse_url($redirect,PHP_URL_QUERY),$query);PHPUnit\Framework\Assert::assertSame($error?'1':'0',(string)($query['df_error']??''),$query['df_message']??'Portal error');PHPUnit\Framework\Assert::assertSame(0,$calls);return $query;
 }
}
