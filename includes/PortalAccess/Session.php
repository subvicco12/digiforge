<?php
declare(strict_types=1);
namespace DigiForge\PortalAccess;
/** An additional DigiForge cookie; never destroys or changes a WordPress session. */
final class Session {
 public static function name():string{return 'digiforge_portal_'.substr(hash('sha256',home_url('/')),0,12);}
 public static function start():true|\WP_Error {
  $actor=get_current_user_id();$s=Store::read($actor);if($actor<1||is_wp_error($s)||(is_array($s)&&$s['role']===GlobalOwnerAuthority::ROLE&&!GlobalOwnerAuthority::active($s))||($s!==null&&!$s['enabled'])||wp_get_session_token()==='')return Policy::error('session');
  $payload=$actor.':'.($s['generation']??0).':'.(time()+3600).':'.hash('sha256',wp_get_session_token());$value=base64_encode($payload).'.'.hash_hmac('sha256',$payload,wp_salt('auth'));$_COOKIE[self::name()]=$value;
  if(!headers_sent())setcookie(self::name(),$value,['expires'=>time()+3600,'path'=>wp_parse_url(home_url('/'),PHP_URL_PATH)?:'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);return true;
 }
 public static function valid(array $s):bool {
  if(($s['role']??null)===GlobalOwnerAuthority::ROLE&&!GlobalOwnerAuthority::active($s))return false;
  $raw=$_COOKIE[self::name()]??null;if(!is_string($raw)||strlen($raw)>512||!$s['enabled']||get_current_user_id()!==$s['actor_id']||wp_get_session_token()==='')return false;$parts=explode('.',$raw);if(count($parts)!==2)return false;$p=base64_decode($parts[0],true);if(!is_string($p)||!hash_equals(hash_hmac('sha256',$p,wp_salt('auth')),$parts[1]))return false;$v=explode(':',$p);return count($v)===4&&$v[0]===(string)$s['actor_id']&&$v[1]===(string)$s['generation']&&ctype_digit($v[2])&&(int)$v[2]>time()&&(int)$v[2]<=time()+3600&&hash_equals(hash('sha256',wp_get_session_token()),$v[3]);
 }
}
