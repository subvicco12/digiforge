<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;

/** Durable, fail-closed integration-wide sliding window and replay protection. */
final class ReadBudget
{
 public function reserve(ExternalOperationIntent $intent):true|\WP_Error {
  global $wpdb;
  if(!function_exists('wp_get_session_token')||!is_object($wpdb))return self::error();
  $session=wp_get_session_token();if(!is_string($session)||$session==='')return self::error();
  $table=$wpdb->options;$wpdb->last_error='';
  $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));
  if($wpdb->last_error!==''||strtoupper((string)$engine)!=='INNODB')return self::error();
  // INSERT IGNORE is replay-safe only with the native unique option-name index intact.
  $indexes=$wpdb->get_results("SHOW INDEX FROM $table",ARRAY_A);
  $unique=false;
  if(is_array($indexes))foreach($indexes as $index){
   $name=$index['Key_name']??null;
   if(!is_string($name))continue;
   $columns=array_filter($indexes,static fn(array $row):bool=>($row['Key_name']??null)===$name);
   if(count($columns)===1&&($index['Column_name']??null)==='option_name'&&(int)($index['Non_unique']??1)===0&&(int)($index['Seq_in_index']??0)===1&&($index['Sub_part']??null)===null)$unique=true;
  }
  if($wpdb->last_error!==''||!$unique)return self::error();
  $active=$wpdb->get_var('SELECT @@session.in_transaction');
  if($wpdb->last_error!==''||(string)$active!=='0')return self::error();
  $window='df_printify_read_window_'.hash('sha256','printify|'.$intent->integrationId);
  // The intent marker outlives rolling windows: exhausted/replayed requests cannot re-arm.
  $marker='df_printify_read_intent_'.hash('sha256',$intent->intentId);
  if($wpdb->query('START TRANSACTION')===false)return self::error();
  try {
   $wpdb->last_error='';
   $insert=$wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table (option_name,option_value,autoload) VALUES (%s,%s,'no')",$window,'[]'));
   if($insert===false||$wpdb->last_error!=='')throw new \RuntimeException();
   $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM $table WHERE option_name=%s FOR UPDATE",$window));
   if($wpdb->last_error!==''||!is_string($raw))throw new \RuntimeException();
   $times=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
   if(!is_array($times)||!array_is_list($times))throw new \RuntimeException();
   $now=time();foreach($times as $time)if(!is_int($time)||$time>$now)throw new \RuntimeException();
   $times=array_values(array_filter($times,static fn(int $time):bool=>$time>$now-300));
   if(count($times)>=10||count(array_filter($times,static fn(int $time):bool=>$time>$now-60))>=3)throw new \RuntimeException();
   $ok=$wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table (option_name,option_value,autoload) VALUES (%s,%s,'no')",$marker,(string)$now));
   if($ok!==1||$wpdb->last_error!=='')throw new \RuntimeException();
   $times[]=$now;
   $ok=$wpdb->query($wpdb->prepare("UPDATE $table SET option_value=%s WHERE option_name=%s",json_encode($times,JSON_THROW_ON_ERROR),$window));
   if($ok!==1||$wpdb->last_error!=='')throw new \RuntimeException();
   if($wpdb->query('COMMIT')===false||$wpdb->last_error!=='')return self::error();
   return true;
  }catch(\Throwable){$wpdb->query('ROLLBACK');return self::error();}
 }
 private static function error():\WP_Error {return new \WP_Error('printify_read_budget_denied','Read reservation unavailable or exhausted.',['status'=>429]);}
}
