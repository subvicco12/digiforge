<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Security\Logger;
use WP_Error;
/** Local emergency revocation only. Never creates acceptance permission. No cache or migration. */
final class PrintifyAcceptanceSafetyGeneration {
 private const KEY='df_acceptance_safety_generation_v1';
 private static function error():WP_Error {return new WP_Error('printify_acceptance_generation_unavailable','Current acceptance safety generation is unavailable.',['status'=>409,'retry_permitted'=>false]);}
 /** Atomic compare/exchange. Concurrent losers deny, never overwrite a newer event. */
 public static function terminate():string|WP_Error {
  if(is_wp_error(PrintifyProductPipeline::durableDispatchSession()))return self::error();
  global $wpdb;$wpdb->last_error='';$old=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE BINARY option_name=BINARY %s',self::KEY));if($wpdb->last_error!=='')return self::error();
  try{$generation=bin2hex(random_bytes(32));$record=['generation'=>$generation,'event'=>'EMERGENCY_TERMINATION','issued_at'=>time()];$record['seal']=hash_hmac('sha256',\DigiForge\Listings\Validator::canonicalJson($record),wp_salt('auth'));$raw=wp_json_encode($record);}catch(\Throwable){return self::error();}
  $wpdb->last_error='';$changed=$old===null?$wpdb->insert($wpdb->options,['option_name'=>self::KEY,'option_value'=>$raw,'autoload'=>'off']):$wpdb->query($wpdb->prepare('UPDATE '.$wpdb->options.' SET option_value=%s WHERE BINARY option_name=BINARY %s AND BINARY option_value=BINARY %s',$raw,self::KEY,$old));
  if($changed!==1||$wpdb->last_error!==''||!Logger::write('printify_acceptance_emergency_generation',['generation_sha256'=>$generation],'printify_acceptance_generation',$generation))return self::error();
  // A concurrent newer event must never be reported as this event's usable generation.
  return self::check($generation)===true?$generation:self::error();
 }
 public static function current():string|WP_Error {
  if(is_wp_error(PrintifyProductPipeline::durableDispatchSession()))return self::error();
  global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE BINARY option_name=BINARY %s',self::KEY));if($wpdb->last_error!==''||!is_string($raw)||strlen($raw)>2048)return self::error();
  try{$r=json_decode($raw,true,8,JSON_THROW_ON_ERROR);if(!is_array($r)||count($r)!==4||($r['event']??null)!=='EMERGENCY_TERMINATION'||!is_int($r['issued_at']??null)||$r['issued_at']>time()||!is_string($r['generation']??null)||!preg_match('/^[a-f0-9]{64}$/D',$r['generation'])||!is_string($r['seal']??null))return self::error();$seal=$r['seal'];unset($r['seal']);if(!hash_equals($seal,hash_hmac('sha256',\DigiForge\Listings\Validator::canonicalJson($r),wp_salt('auth'))))return self::error();}catch(\Throwable){return self::error();}
  $wpdb->last_error='';$rows=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.\DigiForge\Database\Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s LIMIT 2','printify_acceptance_emergency_generation','printify_acceptance_generation',$r['generation']));
  return $wpdb->last_error===''&&is_array($rows)&&count($rows)===1&&(json_decode($rows[0],true)['generation_sha256']??null)===$r['generation']?$r['generation']:self::error();
 }
 public static function check(mixed $generation):true|WP_Error {$current=self::current();return is_string($generation)&&is_string($current)&&hash_equals($generation,$current)?true:self::error();}
}
