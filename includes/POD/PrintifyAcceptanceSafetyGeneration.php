<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Security\Logger;
use WP_Error;
/** Local emergency revocation only. Never creates acceptance permission. No cache or migration. */
final class PrintifyAcceptanceSafetyGeneration {
 private const KEY='df_acceptance_safety_generation_v1';
 private static function fence():PrintifyAcceptanceEmergencyFence {
  if(!defined('DIGIFORGE_PRINTIFY_ACCEPTANCE_FENCE_DIR')||!is_string(DIGIFORGE_PRINTIFY_ACCEPTANCE_FENCE_DIR))throw new \RuntimeException('FENCE_NOT_PROVISIONED');
  return new PrintifyAcceptanceEmergencyFence(DIGIFORGE_PRINTIFY_ACCEPTANCE_FENCE_DIR);
 }
 /** Independent autocommit denial latch. No caller transaction is committed, and no clear API exists. */
 private static function quarantine():bool {
  if(!defined('DB_USER')||!defined('DB_PASSWORD')||!defined('DB_NAME')||!defined('DB_HOST')||!defined('MYSQLI_OPT_READ_TIMEOUT'))return false;
  $peer=null;$mode=(new \mysqli_driver())->report_mode;
  try{global $wpdb;$host=$wpdb->parse_db_host(DB_HOST);
   if(!is_array($host)||!in_array($host[0],['localhost','127.0.0.1','::1'],true)||!preg_match('/^[A-Za-z0-9_]+$/D',$wpdb->options)||(defined('MYSQL_CLIENT_FLAGS')&&MYSQL_CLIENT_FLAGS!==0))return false;
   mysqli_report(MYSQLI_REPORT_OFF);$peer=mysqli_init();if(!$peer||!$peer->options(MYSQLI_OPT_CONNECT_TIMEOUT,1)||!$peer->options(MYSQLI_OPT_READ_TIMEOUT,1))return false;
   $hostname=$host[3]&&extension_loaded('mysqlnd')?'['.$host[0].']':$host[0];
   if(!@$peer->real_connect($hostname,DB_USER,DB_PASSWORD,DB_NAME,$host[1]??0,$host[2]??null))return false;
   if(!$peer->query('SET SESSION innodb_lock_wait_timeout=1, lock_wait_timeout=1'))return false;
   $result=$peer->query('SELECT @@session.autocommit AS autocommit, @@session.in_transaction AS active, @@innodb_flush_log_at_trx_commit AS durable');$state=$result?$result->fetch_assoc():null;
   if(!is_array($state)||(string)($state['autocommit']??'')!=='1'||(string)($state['active']??'')!=='0'||(string)($state['durable']??'')!=='1')return false;
   $query=$peer->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');if(!$query)return false;$table=$wpdb->options;$query->bind_param('s',$table);if(!$query->execute())return false;$result=$query->get_result();$row=$result?$result->fetch_row():null;if(strtoupper((string)($row[0]??''))!=='INNODB')return false;
   if(!$peer->query("INSERT IGNORE INTO `".$table."` (option_name,option_value,autoload) VALUES ('df_acceptance_safety_generation_v1_quarantined','DENIED_UNTIL_SEPARATE_HOST_RECOVERY','off')"))return false;
   $result=$peer->query("SELECT option_value FROM `".$table."` WHERE BINARY option_name=BINARY 'df_acceptance_safety_generation_v1_quarantined'");$row=$result?$result->fetch_row():null;return ($row[0]??null)==='DENIED_UNTIL_SEPARATE_HOST_RECOVERY';
  }catch(\Throwable){return false;}finally{if($peer instanceof \mysqli)try{$peer->close();}catch(\Throwable){}mysqli_report($mode);}
 }
 private static function quarantineAbsent():bool {global $wpdb;$wpdb->last_error='';$v=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE BINARY option_name=BINARY %s',self::KEY.'_quarantined'));return $wpdb->last_error===''&&$v===null;}
 private static function error(string $state='GENERATION_UNAVAILABLE',?bool $durable=null):WP_Error {return new WP_Error('printify_acceptance_generation_unavailable','Current acceptance safety generation is unavailable.',['status'=>409,'retry_permitted'=>false,'revocation_state'=>$state,'durable_revocation_recorded'=>$durable]);}
 /** Atomic compare/exchange. Concurrent losers deny, never overwrite a newer event. */
 public static function terminate():string|WP_Error {
  try{$fence=self::fence()->invalidate();}catch(\Throwable){$latched=self::quarantine();return self::error($latched?'QUARANTINED':'HOST_FAIL_STOP_REQUIRED',$latched);}
  try{return self::fence()->afterDraining(static function()use($fence):string|WP_Error {
  if(!self::quarantineAbsent())return self::error('FENCED_DATABASE_UNAVAILABLE',true);
  if(is_wp_error(PrintifyProductPipeline::durableDispatchSession()))return self::error('FENCED_DATABASE_UNAVAILABLE',true);
  global $wpdb;$wpdb->last_error='';$old=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE BINARY option_name=BINARY %s',self::KEY));if($wpdb->last_error!=='')return self::error('FENCED_DATABASE_UNAVAILABLE',true);
  try{$generation=bin2hex(random_bytes(32));$record=['generation'=>$generation,'fence_sha256'=>$fence,'event'=>'EMERGENCY_TERMINATION','issued_at'=>time()];$record['seal']=hash_hmac('sha256',\DigiForge\Listings\Validator::canonicalJson($record),wp_salt('auth'));$raw=wp_json_encode($record);}catch(\Throwable){return self::error('FENCED_DATABASE_UNAVAILABLE',true);}
  $wpdb->last_error='';$changed=$old===null?$wpdb->insert($wpdb->options,['option_name'=>self::KEY,'option_value'=>$raw,'autoload'=>'off']):$wpdb->query($wpdb->prepare('UPDATE '.$wpdb->options.' SET option_value=%s WHERE BINARY option_name=BINARY %s AND BINARY option_value=BINARY %s',$raw,self::KEY,$old));
  if($changed!==1||$wpdb->last_error!==''||!Logger::write('printify_acceptance_emergency_generation',['generation_sha256'=>$generation],'printify_acceptance_generation',$generation))return self::error('FENCED_DATABASE_UNAVAILABLE',true);
  // A concurrent newer event must never be reported as this event's usable generation.
  return self::check($generation)===true?$generation:self::error('FENCED_DATABASE_UNAVAILABLE',true);
  });}catch(\Throwable $e){return self::error($e->getMessage()==='ACCEPTANCE_FENCE_DRAIN_BUSY'?'FENCED_DRAIN_PENDING':'FENCED_LOCK_UNAVAILABLE',true);}
 }
 /** Lease spans credential admission and dispatch. This helper supplies no transport or credential authority. */
 public static function withDispatchLease(mixed $generation,callable $work):mixed {
  try{return self::fence()->duringDispatch(static function()use($generation,$work):mixed {if(self::check($generation)!==true)return self::error('LEASE_GENERATION_INVALID');$result=$work();return self::check($generation)===true?$result:self::error('LEASE_OUTCOME_UNKNOWN');});}catch(\Throwable){return self::error('LEASE_UNAVAILABLE');}
 }

 public static function current():string|WP_Error {
  try{$fence=self::fence()->snapshot();}catch(\Throwable){return self::error();}
  if(!self::quarantineAbsent())return self::error();
  if(is_wp_error(PrintifyProductPipeline::durableDispatchSession()))return self::error();
  global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE BINARY option_name=BINARY %s',self::KEY));if($wpdb->last_error!==''||!is_string($raw)||strlen($raw)>2048)return self::error();
  try{$r=json_decode($raw,true,8,JSON_THROW_ON_ERROR);if(!is_array($r)||count($r)!==5||($r['fence_sha256']??null)!==$fence||($r['event']??null)!=='EMERGENCY_TERMINATION'||!is_int($r['issued_at']??null)||$r['issued_at']>time()||!is_string($r['generation']??null)||!preg_match('/^[a-f0-9]{64}$/D',$r['generation'])||!is_string($r['seal']??null))return self::error();$seal=$r['seal'];unset($r['seal']);if(!hash_equals($seal,hash_hmac('sha256',\DigiForge\Listings\Validator::canonicalJson($r),wp_salt('auth'))))return self::error();}catch(\Throwable){return self::error();}
  $wpdb->last_error='';$rows=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.\DigiForge\Database\Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s LIMIT 2','printify_acceptance_emergency_generation','printify_acceptance_generation',$r['generation']));
  $auditValid=$wpdb->last_error===''&&is_array($rows)&&count($rows)===1&&(json_decode($rows[0],true)['generation_sha256']??null)===$r['generation'];
  try{if(!hash_equals($fence,self::fence()->snapshot())||!self::quarantineAbsent())return self::error();}catch(\Throwable){return self::error();}
  return $auditValid?$r['generation']:self::error();
 }
 public static function check(mixed $generation):true|WP_Error {$current=self::current();return is_string($generation)&&is_string($current)&&hash_equals($generation,$current)?true:self::error();}
}
