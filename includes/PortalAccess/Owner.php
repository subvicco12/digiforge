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
  $s=Store::read($actor);if(is_array($s)&&$s['role']===GlobalOwnerAuthority::ROLE)return GlobalOwnerAuthority::active($s)&&ShopAccess::authorize($shop,'manage_digiforge_settings',$actor)===true?true:Policy::error('global_owner');return Policy::error('global_owner_required');
 }
 public static function require(string $shop):true|\WP_Error{$owner=self::forActor(get_current_user_id(),$shop);if($owner!==true)return $owner;return EmailAuthentication::recent(get_current_user_id())?true:Policy::error('fresh_email_required');}
 public static function enroll(string $shop,string $role,array $caps,int $expiry,string $approval,int $revision,string $request):array|\WP_Error {
  return Policy::error('use_global_bootstrap');
 }
}
