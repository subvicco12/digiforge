<?php
declare(strict_types=1);
/** Disposable worker: load the parent's existing WP database, never reinstall or provision authority. */
if(PHP_SAPI!=='cli'||count($argv)!==2)exit(2);
$jobPath=$argv[1];$stat=@lstat($jobPath);
if(!is_array($stat)||($stat['mode']&0777)!==0600||is_link($jobPath))exit(2);
$job=json_decode((string)file_get_contents($jobPath),true);
if(!is_array($job)||($job['synthetic_only']??null)!==true||!in_array($job['phase']??null,['before_commit','after_commit'],true))exit(2);
if(!is_string($job['config']??null)||!is_file($job['config'])||!is_string($job['autoload']??null)||!is_file($job['autoload']))exit(2);
define('WP_TESTS_CONFIG_FILE_PATH',$job['config']);
$allowed=['DIGIFORGE_CREDENTIAL_KEY','DIGIFORGE_SHOP_ACCESS_TRUST_KEY','DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE','DIGIFORGE_PORTAL_OWNER_APPROVAL_FILE'];
foreach(($job['constants']??[])as $name=>$value){if(!in_array($name,$allowed,true)||!is_string($value))exit(2);define($name,$value);}
define('WP_HTTP_BLOCK_EXTERNAL',true);putenv('WP_TESTS_SKIP_INSTALL=1');
$GLOBALS['wp_tests_options']['upload_path']=$job['upload_path'];
require $job['autoload'];
require dirname(__DIR__,5).'/tests/wordpress/bootstrap.php';
require dirname(__DIR__,2).'/bootstrap.php';
if(!defined('WP_TESTS_DOMAIN')||wp_get_environment_type()!=='local')exit(2);
global $wpdb;
if($wpdb->prefix!==$job['prefix']||home_url('/')!==$job['home'])exit(3);
$_COOKIE=$job['cookies'];wp_set_current_user((int)$job['actor']);
$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');
add_filter('pre_http_request',static function()use($job){file_put_contents($job['network'],'unexpected_http',LOCK_EX);chmod($job['network'],0600);exit(90);},PHP_INT_MAX);
$wrote=false;$hit=false;
add_filter('query',static function(string $sql)use($job,$wpdb,&$wrote,&$hit):string{
    if(str_starts_with($sql,'INSERT INTO `'.\DigiForge\Database\Tables::fulfillment_plans().'`'))$wrote=true;
    if(!$hit&&$wrote&&$sql==='COMMIT'){
        $hit=true;$committed=false;
        if($job['phase']==='after_commit'){
            // Execute the real COMMIT; the guard prevents recursive interception.
            if($wpdb->query('COMMIT')===false)exit(81);
            if((string)$wpdb->get_var('SELECT @@session.in_transaction')!=='0')exit(82);
            $committed=true;
        }
        file_put_contents($job['ready'],wp_json_encode(['phase'=>$job['phase'],'pid'=>getmypid(),'actual_commit'=>$committed]),LOCK_EX);chmod($job['ready'],0600);
        // Parent sends SIGKILL. A timeout is an explicit test failure, never a simulated crash.
        $deadline=microtime(true)+25;while(microtime(true)<$deadline)usleep(10000);exit(83);
    }
    return $sql;
});
$_SERVER['REQUEST_METHOD']='POST';$_POST=$job['confirmation'];
try{do_action('admin_post_'.$_POST['action']);}catch(Throwable){}
file_put_contents($job['unexpected'],'confirmation_returned_without_crash_boundary',LOCK_EX);chmod($job['unexpected'],0600);exit(84);
