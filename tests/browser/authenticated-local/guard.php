<?php
declare(strict_types=1);
// Disposable fixture only. Never load this file from a production plugin path.
$fixture=getenv('DF_LOCAL_BROWSER_DIR');
if(getenv('DF_LOCAL_BROWSER_DISPOSABLE')!=='1'||!is_string($fixture)||!is_file($fixture.'/disposable.marker'))throw new RuntimeException('Explicit disposable browser fixture required.');
if(PHP_SAPI==='cli-server'&&(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)||!preg_match('/^127\.0\.0\.1:[0-9]+$/D',$_SERVER['HTTP_HOST']??''))){http_response_code(403);exit;}
if(defined('WP_INSTALLING')&&WP_INSTALLING)return;
$trust=$fixture.'/public-authority.php';if(is_file($trust))require_once $trust;
add_filter('plugins_url',static fn($url,$path,$plugin)=>$plugin===getenv('DF_LOCAL_BROWSER_REPO').'/digiforge.php'?WP_HOME.'/df-assets/'.ltrim($path,'/'):$url,10,3);
require_once getenv('DF_LOCAL_BROWSER_REPO').'/digiforge.php';
add_filter('show_admin_bar','__return_false');
add_action('init',static function():void {foreach(['_maybe_update_core','_maybe_update_plugins','_maybe_update_themes']as $hook)remove_action('admin_init',$hook);},PHP_INT_MAX);
add_filter('pre_http_request',static function($pre,$args,$url)use($fixture){file_put_contents($fixture.'/violations.jsonl',json_encode(['type'=>'server_http','url_sha256'=>hash('sha256',(string)$url)])."\n",FILE_APPEND|LOCK_EX);return new WP_Error('local_browser_offline','All provider/network HTTP is forbidden.');},PHP_INT_MAX,3);

if(PHP_SAPI==='cli-server'){
 if(is_file($fixture.'/receipt-failure.marker'))add_filter('query',static function(string $sql):string {return str_starts_with($sql,'SELECT option_id FROM ')&&str_contains($sql,'digiforge_manual_research_consumed_')?'SELECT * FROM nonexistent_browser_receipt_fixture':$sql;});
 add_filter('query',static function(string $sql)use($fixture):string {
  if(preg_match('/^\s*(INSERT(?:\s+IGNORE)?\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO|ALTER\s+TABLE|CREATE\s+TABLE|DROP\s+TABLE)\s+`?([a-zA-Z0-9_]+)/i',$sql,$match)&&str_contains($match[2],'_digiforge_')&&!str_ends_with($match[2],'_digiforge_audit_log')){
   if(preg_match('/^(ALTER|CREATE)\b/i',$match[1])&&!did_action('init')){file_put_contents($fixture.'/bootstrap-ddl.jsonl',json_encode(['type'=>'fixture_bootstrap_ddl','sql_sha256'=>hash('sha256',$sql)])."\n",FILE_APPEND|LOCK_EX);return $sql;}
   file_put_contents($fixture.'/violations.jsonl',json_encode(['type'=>'business_write','sql_sha256'=>hash('sha256',$sql)])."\n",FILE_APPEND|LOCK_EX);throw new RuntimeException('Business mutation forbidden by disposable browser boundary.');
  }return $sql;
 });
}
