<?php
declare(strict_types=1);
namespace DigiForge\PortalAccess;
/** Bounded local counters; identifiers are keyed hashes, never emails, passwords or addresses. */
final class Limiter {
 public static function reserve(string $subject,int $limit,int $window=900):bool {
  global $wpdb;$key='digiforge_portal_rate_'.hash_hmac('sha256',$subject,wp_salt('nonce'));$lock='df_rate_'.substr(hash('sha256',$key),0,48);
  if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1')return false;
  try{$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key));if($wpdb->last_error!=='')return false;$s=$raw===null?['until'=>time()+$window,'hits'=>0]:json_decode((string)$raw,true);if(!is_array($s)||!is_int($s['until']??null)||!is_int($s['hits']??null)||$s['hits']<0)return false;if($s['until']<=time())$s=['until'=>time()+$window,'hits'=>0];if($s['hits']>=$limit)return false;$s['hits']++;$r=$raw===null?$wpdb->insert($wpdb->options,['option_name'=>$key,'option_value'=>wp_json_encode($s),'autoload'=>'no']):$wpdb->update($wpdb->options,['option_value'=>wp_json_encode($s)],['option_name'=>$key]);return $r===1&&$wpdb->last_error==='';}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
 }
}
