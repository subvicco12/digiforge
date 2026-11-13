<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||count($argv)!==4)exit(2);$f=json_decode(file_get_contents($argv[1]),true);if(($f['synthetic_only']??null)!==true)exit(2);
define('WP_TESTS_CONFIG_FILE_PATH',$f['config']);define('DIGIFORGE_SHOP_ACCESS_TRUST_KEY',$f['trust_key']);define('DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE',$f['authority_file']);define('WP_HTTP_BLOCK_EXTERNAL',true);putenv('WP_TESTS_SKIP_INSTALL=1');
require dirname(__DIR__,3).'/vendor/autoload.php';require dirname(__DIR__).'/bootstrap.php';wp_set_current_user($f['actor']);$_COOKIE[LOGGED_IN_COOKIE]=$f['cookie'];
global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');file_put_contents($argv[2].'.ready','1');$until=microtime(true)+30;while(!is_file($argv[3])){if(microtime(true)>$until)exit(3);usleep(10000);}
$t=DigiForge\Connector\Printify\NativeHeldJournalSchema::tables()[0];$counts=['prefix'=>$wpdb->prefix,'rows'=>(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$t),'op_rows'=>(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$t.' WHERE operation_id=%s',$f['operation'])),'scoped_rows'=>(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$t.' WHERE operation_id=%s AND actor_id=%d AND shop_key=%s',$f['operation'],$f['actor'],'personalized_pod'))];
$crashed=false;if(isset($f['crash_mode']))add_filter('query',static function(string $sql)use($f,$t,&$crashed,$wpdb):string {
 if($f['crash_mode']==='before_header'&&str_starts_with($sql,'UPDATE `'.$t.'`'))exit(73);
 if($f['crash_mode']==='after_commit'&&$sql==='COMMIT'&&!$crashed){$crashed=true;$wpdb->query('COMMIT');exit(74);}return $sql;
});
$r=(new DigiForge\Connector\Printify\NativeHeldJournal())->transition($f['operation'],'personalized_pod',$f['actor'],1,'FENCE');file_put_contents($argv[2],is_wp_error($r)?'0':'1');file_put_contents($argv[2].'.detail',wp_json_encode($counts+['result'=>is_wp_error($r)?$r->get_error_code():'LOCAL_FENCE_PERSISTED']));
