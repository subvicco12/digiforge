<?php
declare(strict_types=1);
$dir=getenv('DF_LOCAL_BROWSER_DIR');$repo=getenv('DF_LOCAL_BROWSER_REPO');
if(getenv('DF_LOCAL_BROWSER_DISPOSABLE')!=='1'||!is_string($dir)||!is_file($dir.'/disposable.marker'))throw new RuntimeException('Explicit disposable fixture required.');
if(PHP_SAPI==='cli-server'&&(($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'||!preg_match('/^127\.0\.0\.1:[0-9]+$/D',$_SERVER['HTTP_HOST']??''))){http_response_code(403);exit;}
if(defined('WP_INSTALLING')&&WP_INSTALLING)return;
if(is_file($dir.'/public-authority.php'))require_once $dir.'/public-authority.php';
// plugin_dir_url() is evaluated while the plugin loads, before init.
add_filter('plugins_url',static fn($url,$path,$plugin)=>$plugin===$repo.'/digiforge.php'?WP_HOME.'/df-assets/'.ltrim($path,'/'):$url,10,3);
if(!defined('DF_SHOP2_BROWSER_SETUP')){
    require_once $repo.'/digiforge.php';
    require_once $repo.'/services/native-authority/wordpress/bootstrap.php';
}
add_filter('show_admin_bar','__return_false');
add_filter('pre_http_request',static function($pre,$args,$url)use($dir){file_put_contents($dir.'/violations.jsonl',json_encode(['type'=>'outbound_http','url_sha256'=>hash('sha256',(string)$url)])."\n",FILE_APPEND|LOCK_EX);return new WP_Error('shop2_browser_offline','Outbound HTTP forbidden.');},PHP_INT_MAX,3);
add_filter('pre_wp_mail',static function($pre,$mail)use($dir){if(preg_match('/\b([0-9]{8})\b/',$mail['message']??'',$m)){file_put_contents($dir.'/otp.json',json_encode(['code'=>$m[1]]));chmod($dir.'/otp.json',0600);}return true;},PHP_INT_MAX,2);
add_action('init',static function():void{foreach(['_maybe_update_core','_maybe_update_plugins','_maybe_update_themes']as $hook)remove_action('admin_init',$hook);},PHP_INT_MAX);
add_action('init',static function()use($dir):void{
    if(!\DigiForge\Core\Settings::safety_locked()||!\DigiForge\Core\Settings::get('stop_all',true)||\DigiForge\Core\Settings::get('automation_armed',false)){
        file_put_contents($dir.'/violations.jsonl',"{\"type\":\"safety_hold_changed\"}\n",FILE_APPEND|LOCK_EX);
        throw new RuntimeException('Disposable acceptance refuses changed safety holds.');
    }
},PHP_INT_MAX);
