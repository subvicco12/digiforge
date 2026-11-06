<?php
declare(strict_types=1);
namespace DigiForge\PortalAccess;
/** DigiForge profiles only restrict native signed ACL and ordinary WordPress capabilities. */
final class Policy {
 public const MODULES=['overview'=>'manage_digiforge','research'=>'manage_digiforge_research','products'=>'manage_digiforge_products','personalized_pod'=>'manage_digiforge_pod','digital'=>'manage_digiforge_digital','integrations'=>'manage_digiforge_connections','orders'=>'manage_digiforge_orders','listings'=>'manage_digiforge_listings','production'=>'manage_digiforge_production','ai'=>'manage_digiforge_ai','analytics'=>'view_digiforge_analytics','settings'=>'manage_digiforge_settings'];
 public static function permissions(string $role,array $modules):array|\WP_Error {
  if(!in_array($role,['Admin','Operator','Viewer'],true)||!array_is_list($modules)||!$modules||count($modules)>count(self::MODULES)||count(array_unique($modules,SORT_REGULAR))!==count($modules))return self::error('role');
  $caps=['manage_digiforge'];foreach($modules as $m){if(!is_string($m)||!isset(self::MODULES[$m])||($m==='settings'&&$role!=='Admin'))return self::error('module');$caps[]=self::MODULES[$m];}return array_values(array_unique($caps));
 }
 public static function error(string $code,int $status=403):\WP_Error{return new \WP_Error('digiforge_portal_'.$code,'DigiForge access is unavailable or not permitted.',['status'=>$status,'retry_permitted'=>false]);}
}
