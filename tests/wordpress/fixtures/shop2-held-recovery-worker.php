<?php
declare(strict_types=1);
// Disposable test worker only. Uses the same real plugin/bootstrap/database as its parent.
if(PHP_SAPI!=='cli'||count($argv)!==3)exit(2);
$f=json_decode((string)file_get_contents($argv[1]),true);
if(!is_array($f)||($f['synthetic_only']??null)!==true)exit(2);
if(is_string($f['config']??null)&&$f['config']!=='')define('WP_TESTS_CONFIG_FILE_PATH',$f['config']);
define('DIGIFORGE_SHOP_ACCESS_TRUST_KEY',$f['trust_key']);define('DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE',$f['authority_file']);
if(!defined('WP_HTTP_BLOCK_EXTERNAL'))define('WP_HTTP_BLOCK_EXTERNAL',true);
putenv('WP_TESTS_SKIP_INSTALL=1');require $f['autoload'];require dirname(__DIR__).'/bootstrap.php';
wp_set_current_user($f['actor']);$_COOKIE=$f['cookies'];
add_filter('pre_http_request',static function(){exit(90);},PHP_INT_MAX);
global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');
$service=new DigiForge\Queue\Shop2HeldJobRecovery();
$view=$service->inspect('personalized_pod',$f['order'],$f['intent'],$f['job']);
if(is_wp_error($view)||$view['snapshot_hash']!==$f['snapshot']){
    $tables=DigiForge\Database\Tables::class;
    file_put_contents($argv[2],wp_json_encode(['error'=>is_wp_error($view)?$view->get_error_message():'snapshot_changed','expected_hash'=>$f['snapshot'],'observed_hash'=>is_wp_error($view)?null:$view['snapshot_hash'],'prefix'=>$wpdb->prefix,'job_rows'=>(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$tables::jobs().' WHERE id=%d',$f['job'])),'order_rows'=>(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$tables::orders().' WHERE id=%d',$f['order'])),'intent_rows'=>(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$tables::fulfillment_intents().' WHERE id=%d',$f['intent']))]));exit(11);
}
if($f['action']==='START_EXIT'){
    $repository=new DigiForge\Queue\JobRepository();
    if(!$repository->transition($f['job'],'BLOCKED','QUEUED')||!$repository->acquireLease($f['job'],'disposable_child')||!$repository->start($f['job'],'disposable_child')){file_put_contents($argv[2],'actual_lease_start_failed');exit(12);}
    file_put_contents($argv[2],'actual_job_started_before_abrupt_exit');exit(73);
}
if($f['action']!=='QUARANTINE_AFTER_COMMIT')exit(2);
$lost=false;
add_filter('query',static function(string $sql)use(&$lost,$wpdb,$argv):string{
    if($sql==='COMMIT'&&!$lost){$lost=true;if($wpdb->query('COMMIT')===false)exit(13);file_put_contents($argv[2],'actual_quarantine_commit_acknowledgement_lost');exit(74);}
    return $sql;
});
$result=$service->quarantine('personalized_pod',$f['order'],$f['intent'],$f['job'],$f['snapshot']);
file_put_contents($argv[2],is_wp_error($result)?$result->get_error_code():'unexpected_acknowledgement');exit(14);
