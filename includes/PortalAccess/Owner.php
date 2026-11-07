<?php
declare(strict_types=1);
namespace DigiForge\PortalAccess;
use DigiForge\Security\{ShopAccess,ShopAccessRepository};
/** Intended email identifies the account to verify; signed OWNER plus audited enrollment authorizes. */
final class Owner {
 public const EMAIL='subvicco12@gmail.com';
 public static function signed(int $actor,string $shop):array|\WP_Error {
  $u=get_userdata($actor);if(!$u||strtolower($u->user_email)!==self::EMAIL||!user_can($actor,'manage_digiforge_settings'))return Policy::error('owner');
  $a=ShopAccessRepository::authorityFor($actor,$shop,['manage_digiforge_settings'],time()+1);return !is_wp_error($a)&&$a['record']['authority']==='OWNER'?$a:Policy::error('owner');
 }
 public static function forActor(int $actor,string $shop):true|\WP_Error {
  $s=Store::read($actor);if(is_array($s)&&$s['role']===GlobalOwnerAuthority::ROLE)return GlobalOwnerAuthority::active($s)&&ShopAccess::authorize($shop,'manage_digiforge_settings',$actor)===true?true:Policy::error('global_owner');$a=self::signed($actor,$shop);if(is_wp_error($s)||$s===null||!$s['enabled']||$s['role']!=='Primary Owner'||is_wp_error($a)||($s['authority_hashes'][$shop]??null)!==$a['hash']||ShopAccess::authorize($shop,'manage_digiforge_settings',$actor)!==true)return Policy::error('owner');return true;
 }
 public static function require(string $shop):true|\WP_Error{return self::forActor(get_current_user_id(),$shop);}
 public static function enroll(string $shop,string $role,array $caps,int $expiry,string $approval,int $revision,string $request):array|\WP_Error {
  $id=get_current_user_id();$a=self::signed($id,$shop);if(is_wp_error($a)||!in_array('manage_digiforge_settings',$caps,true)||!in_array('manage_digiforge',$caps,true))return Policy::error('owner');$old=Store::read($id);if(is_wp_error($old)||($old!==null&&($old['role']!=='Primary Owner'||$old['revision']>=1000)))return Policy::error('owner');
  $repo=new ShopAccessRepository();$r=$repo->enrollOwner($shop,$role,$caps,$expiry,$approval,$request,$revision);if(is_wp_error($r))return $r;$shops=$old['shops']??[];$shops[$shop]=$caps;$hashes=$old['authority_hashes']??[];$hashes[$shop]=$a['hash'];
  $saved=Store::save(['actor_id'=>$id,'role'=>'Primary Owner','enabled'=>true,'revision'=>($old['revision']??0)+1,'generation'=>($old['generation']??0)+1,'shops'=>$shops,'authority_hashes'=>$hashes],$old['revision']??0,$request);
  if(!is_wp_error($saved)){Session::start();}return $saved;
 }
}
