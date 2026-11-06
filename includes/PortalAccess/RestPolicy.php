<?php
declare(strict_types=1);
namespace DigiForge\PortalAccess;
use DigiForge\Security\{ShopAccess,ShopAccessPolicy,ShopAccessRestGuard,ShopAccessTargetScope};
/** Restrictive admission only: original permission callbacks and execution interlocks still run. */
final class RestPolicy {
 public static function contract(\WP_REST_Request $request):?array {
  foreach(RestRoutes::ENTRIES as $row){
   if($request->get_method()!==$row[1]||preg_match('#^'.str_replace('#','\\#',$row[0]).'$#D',$request->get_route(),$matches)!==1)continue;
   // Controllers cast numeric IDs. Reject alternate spellings before any module
   // or stored-target matcher can fall back to a less restrictive global route.
   foreach(['id','evidence_id'] as $field)if(isset($matches[$field])){
    $id=$matches[$field];$max=(string)PHP_INT_MAX;
    if(preg_match('/^[1-9][0-9]*$/D',$id)!==1||strlen($id)>strlen($max)||(strlen($id)===strlen($max)&&strcmp($id,$max)>0))return null;
   }
   return $row;
  }
  return null;
 }
 public static function callbackName(mixed $callback):string {
  if(is_array($callback)&&count($callback)===2)return (is_object($callback[0])?get_class($callback[0]):$callback[0]).'::'.$callback[1];
  if($callback instanceof \Closure){$r=new \ReflectionFunction($callback);$v=$r->getStaticVariables();return ($r->getClosureScopeClass()?->getName()??'closure').'::{closure}'.(isset($v['type'])?':'.$v['type']:'');}
  return is_string($callback)?$callback:get_debug_type($callback);
 }
 /** Exact independent boundaries retain their own authentication; no general prefix exemption. */
 public static function independent(\WP_REST_Request $r):bool {
  $route=$r->get_route();$method=$r->get_method();
  return ($method==='POST'&&$route==='/digiforge/v1/etsy/webhook')||
   ($method==='GET'&&in_array($route,['/digiforge/v1/health','/digiforge/v1/recovery/verification-snapshot'],true))||
   ($method==='GET'&&preg_match('#^/digiforge/v1/recovery/evidence/database-backup/marker/[a-f0-9]{64}$#D',$route)===1)||
   (preg_match('#^/digiforge/v1/customer-editor/[A-Za-z0-9_-]{1,80}/(view|upload|fields|approve)$#D',$route,$m)===1&&$method===($m[1]==='view'?'GET':'POST'));
 }
 public static function admit(\WP_REST_Request $r,array $handler,?array $profile):true|\WP_Error {
  $contract=self::contract($r);if($contract===null)return Policy::error('unmapped_route');
  // Callback identity is part of the recorded contract, including independent boundaries.
  foreach(['callback'=>2,'permission_callback'=>3] as $key=>$index)if(isset($handler[$key])&&self::callbackName($handler[$key])!==$contract[$index])return Policy::error('route_contract');
  if(self::independent($r))return true;
  if(str_starts_with($r->get_route(),'/digiforge/v1/shop-access/'))return self::ownerAdmission($r,$profile);
  $forceGlobal=false;
  $sets=ShopAccessRestGuard::capabilitySets($r->get_route(),$r->get_method());
  // Finance is outside assignable modules; there is deliberately no blanket bypass.
  if(str_starts_with($r->get_route(),'/digiforge/v1/finance/'))$sets=[['manage_digiforge_finance']];
  // Native read fallbacks to overview must not erase an explicit module selection.
  if(preg_match('#^/digiforge/v1/(products|product-families|product-versions|opportunities)(/|$)#D',$r->get_route()))$sets=[['manage_digiforge_products']];
  if(str_starts_with($r->get_route(),'/digiforge/v1/digital'))$sets=[['manage_digiforge_digital']];
  if(in_array($r->get_route(),['/digiforge/v1/etsy/taxonomy-discovery','/digiforge/v1/etsy/taxonomy-verify'],true))$sets=[['manage_digiforge_connections','manage_digiforge_automation']];
  if(in_array($r->get_route(),['/digiforge/v1/etsy/controlled-draft','/digiforge/v1/etsy/controlled-listing-image','/digiforge/v1/etsy/controlled-digital-file'],true)){
   $caps=['manage_digiforge_connections','manage_digiforge_listings'];if(str_ends_with($r->get_route(),'controlled-digital-file'))$caps[]='manage_digiforge_digital';$sets=[$caps];
  }
  if(preg_match('#^/digiforge/v1/research/candidates/[1-9][0-9]*/promote$#D',$r->get_route()))$sets=[['manage_digiforge_research','manage_digiforge_products']];
  if(preg_match('#^/digiforge/v1/research/candidates/[1-9][0-9]*/(retry-product-factory|resume-product-factory|review)$#D',$r->get_route(),$operation)){
   $body=$r->get_json_params();
   // APPROVED review emits a scheduling event. Unknown/malformed bodies may not
   // gain the narrower rejection-only admission; original body checks still run.
   if($operation[1]!=='review'||!is_array($body)||($body['decision']??null)!=='REJECTED'){$sets=[['manage_digiforge_research','manage_digiforge_products','manage_digiforge_ai','manage_digiforge_production','manage_digiforge_listings']];$forceGlobal=true;}
   // Scheduling resolves source ancestry in factory-owned code. Until it has a
   // concordant scope contract, a candidate claim cannot scope that workflow.
  }
  foreach($sets as $caps){
   $allowed=ShopAccessPolicy::canonicalShops();
   if($profile!==null){$allowed=[];foreach($profile['shops'] as $shop=>$assigned)if(array_diff($caps,$assigned)===[])$allowed[]=$shop;}
   foreach($caps as $cap){$native=ShopAccess::allowedShops($cap);if(is_wp_error($native))return $native;$allowed=array_values(array_intersect($allowed,$native));}
   if($allowed===[])continue;
   foreach(['shop','shop_key'] as $field){$claim=$r->get_param($field);if($claim!==null&&(!is_string($claim)||!ShopAccessPolicy::validShop($claim)||!in_array($claim,$allowed,true)))return Policy::error('shop_scope');}
   $target=$forceGlobal?false:ShopAccessTargetScope::forRequest($r,$allowed);if(is_wp_error($target))return $target;
   if(is_string($target))foreach(['shop','shop_key'] as $field){$claim=$r->get_param($field);if($claim!==null&&$claim!==$target)return Policy::error('shop_scope');}
   $shops=is_string($target)?[$target]:ShopAccessPolicy::canonicalShops();
   // Unscoped collections and mutations require all shops, even with supplied claims.
   if(array_diff($shops,$allowed)!==[])continue;
   foreach($shops as $shop)foreach($caps as $cap){$decision=ShopAccess::authorize($shop,$cap);if($decision!==true)return is_wp_error($decision)?$decision:Policy::error('native_scope');}
   return true;
  }
  return Policy::error('module_scope');
 }
 private static function ownerAdmission(\WP_REST_Request $r,?array $profile):true|\WP_Error {
  if($profile===null)return true; // Native signed OWNER/DELEGATE callback remains mandatory.
  if($profile['role']!=='Primary Owner')return Policy::error('owner');
  if($r->get_route()==='/digiforge/v1/shop-access/shops')return true; // allowedShops applies the overlay.
  $body=$r->get_method()==='GET'?$r->get_query_params():$r->get_json_params();
  if(!is_array($body)||strlen((string)$r->get_body())>16384)return Policy::error('owner_scope');
  $shop=$body[$r->get_method()==='GET'?'shop_key':'shop']??null;
  if(!is_string($shop)||Owner::require($shop)!==true)return Policy::error('owner_scope');
  foreach(['shop','shop_key'] as $field){$claim=$r->get_param($field);if($claim!==null&&$claim!==$shop)return Policy::error('owner_scope');}
  if($r->get_method()==='POST'&&$r->get_route()==='/digiforge/v1/shop-access/grants'){
   $caps=$body['caps']??null;if(!is_array($caps)||!array_is_list($caps)||$caps===[])return Policy::error('owner_scope');
   foreach($caps as $cap)if(!is_string($cap)||!Store::permits(get_current_user_id(),$shop,$cap)||ShopAccess::authorize($shop,$cap)!==true)return Policy::error('owner_scope');
  }
  return true;
 }

}
