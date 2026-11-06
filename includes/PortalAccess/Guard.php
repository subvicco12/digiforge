<?php
declare(strict_types=1);
namespace DigiForge\PortalAccess;
/** Additional negative admission; it never supplies a native capability, grant or authority. */
final class Guard {
 public function register():void{add_filter('rest_request_before_callbacks',[$this,'rest'],-100,3);add_action('all',[$this,'action'],-100);add_action('admin_init',[$this,'page'],-100);}
 public function rest(mixed $result,array $handler,\WP_REST_Request $request):mixed {
  if(is_wp_error($result)||strncasecmp($request->get_route(),'/digiforge/v1/',14)!==0||get_current_user_id()<1)return $result;if(substr($request->get_route(),0,14)!=='/digiforge/v1/')return Policy::error('noncanonical_route');$s=Store::read(get_current_user_id());if($s===null)return $result;if(is_wp_error($s)||!Session::valid($s))return Policy::error('session');
  $needed=self::modules($request->get_route());if($needed!==[]){$allowed=[];foreach($s['shops'] as $shop=>$caps)if(array_diff($needed,$caps)===[])$allowed[]=$shop;$target=$request->get_param('shop')??$request->get_param('shop_key');if($allowed===[]||(is_string($target)&&!in_array($target,$allowed,true))||(str_starts_with($request->get_route(),'/digiforge/v1/shops/personalized_pod/')&&!in_array('personalized_pod',$allowed,true)))return Policy::error('module_scope');}
  if($s['role']==='Viewer'){
   $route=$request->get_route();$safe=preg_match('#^/digiforge/v1/(products|product-families|product-versions|opportunities)(/[1-9][0-9]*)?$#D',$route)===1||in_array($route,['/digiforge/v1/research/sources','/digiforge/v1/research/candidates','/digiforge/v1/research/observations'],true)||preg_match('#^/digiforge/v1/shops/personalized_pod/printify-research/(readiness|snapshots/[a-f0-9]{64})$#D',$route)===1;
   if($request->get_method()!=='GET'||!$safe)return Policy::error('viewer_read_only');
  }
  if(str_starts_with($request->get_route(),'/digiforge/v1/shop-access/')&&$s['role']!=='Primary Owner')return Policy::error('owner');return $result;
 }
 private static function modules(string $route):array {
  $p=substr($route,14);return match(true){
   preg_match('#^(products|product-families|product-versions|opportunities)(/|$)#D',$p)===1=>['manage_digiforge_products'],
   str_starts_with($p,'shops/personalized_pod/printify-research/')=>['manage_digiforge_pod','manage_digiforge_research','manage_digiforge_connections'],
   str_starts_with($p,'printify/')=>['manage_digiforge_pod','manage_digiforge_connections'],
   str_starts_with($p,'research')=>['manage_digiforge_research'],str_starts_with($p,'digital')=>['manage_digiforge_digital'],
   str_starts_with($p,'orders')=>['manage_digiforge_orders'],str_starts_with($p,'listings')=>['manage_digiforge_listings'],
   str_starts_with($p,'production')=>['manage_digiforge_production'],str_starts_with($p,'integrations')=>['manage_digiforge_connections'],
   str_starts_with($p,'pod/')=>['manage_digiforge_pod'],str_starts_with($p,'ai/')=>['manage_digiforge_ai'],
   str_starts_with($p,'finance/')=>['manage_digiforge_finance'],default=>[]};
 }
 public function allowAction(string $action):bool {
  // These handlers only authenticate, verify a subject-bound invitation, or bootstrap
  // reviewed signed authority. They independently validate nonce and exact inputs.
  if(in_array($action,['digiforge_portal_login','digiforge_portal_continue','digiforge_portal_invitation_accept','digiforge_portal_owner_enroll'],true))return true;
  $s=Store::read(get_current_user_id());if($s===null)return true;if(is_wp_error($s)||!Session::valid($s)||$s['role']==='Viewer')return false;
  if(str_starts_with($action,'digiforge_finance_')&&!self::has($s,'manage_digiforge_finance'))return false;
  if(in_array($action,['digiforge_portal_users','digiforge_portal_access'],true)&&$s['role']!=='Primary Owner')return false;return true;
 }
 public function action(string $hook):void{if(!str_starts_with($hook,'admin_post_digiforge_')&&!str_starts_with($hook,'admin_post_nopriv_digiforge_'))return;$a=substr($hook,str_starts_with($hook,'admin_post_nopriv_')?18:11);if(!$this->allowAction($a))$this->deny();}
 private static function has(array $s,string $cap):bool{foreach($s['shops'] as $caps)if(in_array($cap,$caps,true))return true;return false;}
 public function page():void{$p=$_REQUEST['page']??null;if(!is_string($p)||($p!=='digiforge'&&!str_starts_with($p,'digiforge-')))return;$s=Store::read(get_current_user_id());if($s!==null&&(is_wp_error($s)||!Session::valid($s)||$s['role']==='Viewer'||(str_starts_with($p,'digiforge-finance')&&!self::has($s,'manage_digiforge_finance'))))$this->deny();}
 private function deny():never{\DigiForge\Security\Logger::write('portal_operation_denied',['actor_id'=>get_current_user_id()],'portal_access','operation');wp_die('DigiForge access is disabled, expired or read-only. Your WordPress account is unchanged.','',['response'=>403]);}
}
