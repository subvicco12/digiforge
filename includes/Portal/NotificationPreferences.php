<?php
declare(strict_types=1);
namespace DigiForge\Portal;
use WP_Error;
/** Per-operator preferences for the portal's existing status notifications. */
final class NotificationPreferences {
 public const META_KEY='digiforge_show_success_notices';
 public static function show(bool $error,mixed $stored):bool {return $error||$stored!=='0';}
 public function current():bool {return self::show(false,get_user_meta(get_current_user_id(),self::META_KEY,true));}
 public function save(bool $show):true|WP_Error {
  if(!is_user_logged_in()||!current_user_can('manage_digiforge'))return new WP_Error('notification_preferences_denied','Operator access required.',['status'=>403]);
  $value=$show?'1':'0';update_user_meta(get_current_user_id(),self::META_KEY,$value);
  if(get_user_meta(get_current_user_id(),self::META_KEY,true)!==$value)return new WP_Error('notification_preferences_unavailable','Notification preference could not be confirmed.',['status'=>503]);
  return true;
 }
}
