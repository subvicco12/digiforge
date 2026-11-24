<?php
declare(strict_types=1);
namespace DigiForge\PortalAccess;
/** Host approval is not writable by any WordPress/REST administration route. */
final class ServerOwnerApproval {
 public const KEY='digiforge_portal_database_owner_authority';
 public static function approval(string $purpose):array|\WP_Error {
  if(!defined('DIGIFORGE_PORTAL_OWNER_APPROVAL_FILE')||!is_string(DIGIFORGE_PORTAL_OWNER_APPROVAL_FILE))return Policy::error('server_approval_missing');
  $path=DIGIFORGE_PORTAL_OWNER_APPROVAL_FILE;$real=realpath($path);$root=realpath(ABSPATH);
  if($real===false||$root===false||!str_starts_with($path,'/')||str_starts_with($real,$root.'/'))return Policy::error('server_approval_path');
  clearstatcache(true,$path);$s=@lstat($path);if(!is_array($s)||($s['mode']&0170000)!==0100000||($s['mode']&0077)!==0||$s['size']>4096)return Policy::error('server_approval_file');
  $f=@fopen($path,'rb');if($f===false)return Policy::error('server_approval_file');$opened=fstat($f);$raw=stream_get_contents($f,4097);fclose($f);
  if(!is_array($opened)||$opened['ino']!==$s['ino']||$opened['dev']!==$s['dev']||!is_string($raw)||strlen($raw)>4096)return Policy::error('server_approval_file');
  $a=json_decode($raw,true);$now=time();
  if(!is_array($a)||($a['version']??null)!==1||($a['actor_id']??null)!==1||($a['email']??null)!==Owner::EMAIL||($a['site']??null)!==home_url('/')||($a['scope']??null)!==GlobalOwnerAuthority::SCOPE||($a['purpose']??null)!==$purpose||!is_string($a['approval_id']??null)||preg_match('/^[a-f0-9]{64}$/D',$a['approval_id'])!==1||!is_int($a['expires_at']??null)||$a['expires_at']<=$now||$a['expires_at']>$now+86400||!is_int($a['authority_expires_at']??null)||$a['authority_expires_at']<=$now||$a['authority_expires_at']>$now+366*86400)return Policy::error('server_approval_invalid');
  return $a;
 }
 public static function records(array $a):array{return GlobalOwnerAuthority::draft($a['site'],$a['authority_expires_at'],$a['approval_id']);}
 public static function read(bool $allowExpired=false):array|\WP_Error|null {
  global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",self::KEY));if($wpdb->last_error!=='')return Policy::error('authority_storage',503);if($raw===null)return null;
  $s=is_string($raw)&&strlen($raw)<=16384?json_decode($raw,true):null;if(!is_array($s)||!is_array($s['body']??null)||!is_string($s['mac']??null)||!hash_equals(hash_hmac('sha256',(string)wp_json_encode($s['body']),wp_salt('auth')),$s['mac']))return Policy::error('authority_integrity');
  $b=$s['body'];if(($b['version']??null)!==1||($b['site']??null)!==home_url('/')||!is_array($b['records']??null)||!is_string($b['claim_hash']??null))return Policy::error('authority_integrity');
  $a=GlobalOwnerAuthority::resolve($b['records'],home_url('/'),$allowExpired?min(time(),($b['records'][0]['expires_at']??0)-1):time());if(is_wp_error($a))return $a;
  return $a;
 }
 public static function matchesClaim(string $hash):bool {
  global $wpdb;$raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",self::KEY));$s=is_string($raw)?json_decode($raw,true):null;
  return is_array($s)&&($s['body']['claim_hash']??null)===$hash&&!is_wp_error(self::read(true));
 }
 public static function renewClaim(string $oldHash,string $newHash):bool {
  global $wpdb;$raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE",self::KEY));$s=is_string($raw)?json_decode($raw,true):null;
  if(!is_array($s)||($s['body']['claim_hash']??null)!==$oldHash||is_wp_error(self::read()))return false;
  $s['body']['claim_hash']=$newHash;$s['mac']=hash_hmac('sha256',(string)wp_json_encode($s['body']),wp_salt('auth'));
  return $wpdb->update($wpdb->options,['option_value'=>wp_json_encode($s)],['option_name'=>self::KEY,'option_value'=>$raw])===1;
 }
 /** Called only inside Store's claim/profile transaction, never from an endpoint. */
 public static function persist(array $a,string $claimHash,bool $replace):bool {
  global $wpdb;$body=['version'=>1,'site'=>home_url('/'),'records'=>self::records($a),'claim_hash'=>$claimHash];$raw=wp_json_encode(['body'=>$body,'mac'=>hash_hmac('sha256',(string)wp_json_encode($body),wp_salt('auth'))]);
  $used='digiforge_portal_host_approval_'.hash('sha256',$a['approval_id']);
  if($wpdb->insert($wpdb->options,['option_name'=>$used,'option_value'=>$claimHash,'autoload'=>'no'])!==1)return false;
  $value=['option_value'=>$raw];return ($replace?$wpdb->update($wpdb->options,$value,['option_name'=>self::KEY]):$wpdb->insert($wpdb->options,$value+['option_name'=>self::KEY,'autoload'=>'no']))===1;
 }
}
