<?php
declare(strict_types=1);
namespace DigiForge\PortalAccess;
/** Additional negative admission; it never supplies a native capability, grant or authority. */
final class Guard {
 public function register():void{add_filter('rest_request_before_callbacks',[$this,'rest'],-100,3);add_action('all',[$this,'action'],-100);add_action('admin_init',[$this,'page'],-100);}
 public function rest(mixed $result,array $handler,\WP_REST_Request $request):mixed {
  if(is_wp_error($result)||strncasecmp($request->get_route(),'/digiforge/v1/',14)!==0)return $result;
  if(substr($request->get_route(),0,14)!=='/digiforge/v1/')return Policy::error('noncanonical_route');
  $s=get_current_user_id()>0?Store::read(get_current_user_id()):null;
  if(is_wp_error($s)||($s!==null&&(!Session::valid($s)||!$s['enabled'])))return Policy::error('session');
  $decision=RestPolicy::admit($request,$handler,$s);if($decision!==true)return $decision;
  if($s===null)return $result;
  if($s['role']==='Viewer'){
   $route=$request->get_route();$safe=preg_match('#^/digiforge/v1/(products|product-families|product-versions|opportunities)(/[1-9][0-9]*)?$#D',$route)===1||in_array($route,['/digiforge/v1/research/sources','/digiforge/v1/research/candidates','/digiforge/v1/research/observations'],true)||preg_match('#^/digiforge/v1/shops/personalized_pod/printify-research/(readiness|snapshots/[a-f0-9]{64})$#D',$route)===1;
   if($request->get_method()!=='GET'||!$safe)return Policy::error('viewer_read_only');
  }
  if(str_starts_with($request->get_route(),'/digiforge/v1/shop-access/')&&!in_array($s['role'],['Primary Owner',GlobalOwnerAuthority::ROLE],true))return Policy::error('owner');return $result;
 }
 public function allowAction(string $action):bool {
  // These handlers only authenticate, verify a subject-bound invitation, or bootstrap
  // reviewed signed authority. They independently validate nonce and exact inputs.
  if(in_array($action,['digiforge_portal_login','digiforge_portal_continue','digiforge_portal_invitation_accept','digiforge_portal_owner_enroll','digiforge_portal_owner_bootstrap','digiforge_portal_owner_recover','digiforge_portal_owner_renew'],true))return true;
  $s=Store::read(get_current_user_id());if($s===null)return true;if(is_wp_error($s)||!Session::valid($s)||$s['role']==='Viewer')return false;
  if(str_starts_with($action,'digiforge_finance_')&&!self::has($s,'manage_digiforge_finance'))return false;
  if(in_array($action,['digiforge_portal_users','digiforge_portal_access'],true)&&!in_array($s['role'],['Primary Owner',GlobalOwnerAuthority::ROLE],true))return false;return true;
 }
 public function action(string $hook):void{if(!str_starts_with($hook,'admin_post_digiforge_')&&!str_starts_with($hook,'admin_post_nopriv_digiforge_'))return;$a=substr($hook,str_starts_with($hook,'admin_post_nopriv_')?18:11);if(!$this->allowAction($a))$this->deny();}
 private static function has(array $s,string $cap):bool{foreach($s['shops'] as $caps)if(in_array($cap,$caps,true))return true;return false;}
 public function page():void{$p=$_REQUEST['page']??null;if(!is_string($p)||($p!=='digiforge'&&!str_starts_with($p,'digiforge-')))return;$s=Store::read(get_current_user_id());if($s!==null&&(is_wp_error($s)||!Session::valid($s)||$s['role']==='Viewer'||(str_starts_with($p,'digiforge-finance')&&!self::has($s,'manage_digiforge_finance'))))$this->deny();}
 private function deny():never{\DigiForge\Security\Logger::write('portal_operation_denied',['actor_id'=>get_current_user_id()],'portal_access','operation');wp_die('DigiForge access is disabled, expired or read-only. Your WordPress account is unchanged.','',['response'=>403]);}
}
