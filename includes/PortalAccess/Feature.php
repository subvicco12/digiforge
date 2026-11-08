<?php
declare(strict_types=1);
namespace DigiForge\PortalAccess;
final class Feature {
 public const ACTIONS=['digiforge_portal_login','digiforge_portal_continue','digiforge_portal_users','digiforge_portal_invitation_accept','digiforge_portal_owner_enroll','digiforge_portal_owner_bootstrap','digiforge_portal_owner_recover','digiforge_portal_owner_renew','digiforge_portal_owner_revoke'];
 private static bool $registered=false;
 public function register():void {
  if(self::$registered)return;self::$registered=true;add_action('template_redirect',[$this,'route'],-20);add_filter('pre_do_shortcode_tag',[$this,'before'],0,4);add_filter('do_shortcode_tag',[$this,'after'],100,4);
  foreach(self::ACTIONS as $a)add_action('admin_post_'.$a,[$this,'submit']);add_action('admin_post_nopriv_digiforge_portal_login',[$this,'submit']);
  (new Guard())->register();add_action('wp_logout',static function(){unset($_COOKIE[Session::name()]);if(!headers_sent())setcookie(Session::name(),'', ['expires'=>time()-3600,'path'=>wp_parse_url(home_url('/'),PHP_URL_PATH)?:'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);});
 }
 public static function accountBar():string {
  if(!is_user_logged_in())return '<nav aria-label="DigiForge account"><a href="'.esc_url(Authentication::loginUrl()).'">Sign in to DigiForge</a></nav>';
  $html='<nav class="df-account-menu" aria-label="DigiForge account"><span>'.esc_html(wp_get_current_user()->display_name).'</span> <a href="'.esc_url(home_url('/')).'">Portal</a> <a href="'.esc_url(add_query_arg('df_view','owner',home_url('/'))).'">Owner enrollment</a> <a href="'.esc_url(add_query_arg(['df_view'=>'users','df_shop'=>'personalized_pod'],home_url('/'))).'">User management</a> <a href="'.esc_url(wp_logout_url(Authentication::loginUrl())).'">Sign out</a></nav>';return $html;
 }
 public function route():void {
  if(($_GET['df_auth']??null)==='login'){nocache_headers();header('X-Robots-Tag: noindex, nofollow');header('X-Frame-Options: DENY');header('Referrer-Policy: same-origin');echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>DigiForge sign in</title><link rel="stylesheet" href="'.esc_url(DIGIFORGE_URL.'assets/portal-access.css').'"><script defer src="'.esc_url(DIGIFORGE_URL.'assets/portal-access.js').'"></script></head><body>'.Authentication::render().'</body></html>';exit;}
  $post=get_post();$portal=isset($_GET['df_view'])||($post&&has_shortcode($post->post_content,'digiforge_admin_portal'));if($portal){nocache_headers();if(!is_user_logged_in()){wp_safe_redirect(Authentication::loginUrl(Authentication::safeReturn(home_url(wp_unslash($_SERVER['REQUEST_URI']??'/')))));exit;}}
 }
 public function before(mixed $output,string $tag,array $atts,array $match):mixed {
  if($tag!=='digiforge_admin_portal')return $output;nocache_headers();wp_enqueue_style('digiforge-portal-access',DIGIFORGE_URL.'assets/portal-access.css',[],DIGIFORGE_VERSION);wp_enqueue_script('digiforge-portal-access',DIGIFORGE_URL.'assets/portal-access.js',[],DIGIFORGE_VERSION,true);
  if(!is_user_logged_in())return Authentication::render();if(($_GET['df_view']??null)==='invitation')return self::accountBar().Panel::invitation();if(($_GET['df_view']??null)==='owner')return self::accountBar().Panel::bootstrap();$s=Store::read(get_current_user_id());if(is_wp_error($s)||($s!==null&&(!$s['enabled']||!Session::valid($s))))return self::accountBar().'<p role="alert">DigiForge access is disabled, unavailable or this portal session expired. Your WordPress account is unchanged. <a href="'.esc_url(Authentication::loginUrl()).'">Sign in to DigiForge</a></p>';
  if(($_GET['df_view']??null)==='users')return self::accountBar().Panel::render();if(($_GET['df_view']??null)==='owner')return self::accountBar().Panel::bootstrap();if(($_GET['df_view']??null)==='invitation')return self::accountBar().Panel::invitation();return $output;
 }
 public function after(string $html,string $tag,array $atts,array $match):string{if($tag!=='digiforge_admin_portal')return $html;$s=is_user_logged_in()?Store::read(get_current_user_id()):null;if(is_array($s)&&$s['role']==='Viewer')$html='<div class="df-viewer-readonly"><p role="status">Viewer: read-only DigiForge access. Changes and unapproved endpoints are blocked.</p>'.$html.'</div>';return self::accountBar().$html;}
 public function submit():never {
  $action=$_POST['action']??'';$p=wp_unslash($_POST);$result=($_SERVER['REQUEST_METHOD']??'')==='POST'?$this->process(is_string($action)?$action:'',$p):Policy::error('method');
  if(is_wp_error($result))$url=add_query_arg('df_result','blocked',Authentication::loginUrl(Authentication::safeReturn($p['return_to']??'')));else $url=$result['return_to']??add_query_arg(['df_view'=>'users','df_shop'=>is_string($p['shop']??null)?$p['shop']:'personalized_pod','df_result'=>'recorded'],home_url('/'));
  if(!is_wp_error($result)&&($result['verification_required']??false)===true){nocache_headers();header('Referrer-Policy: no-referrer');header('X-Frame-Options: DENY');echo EmailAuthentication::verificationForm($result['selector']);exit;}
  // Invitation credentials are shown once privately; never put them in a query string.
  if(!is_wp_error($result)&&isset($result['token'])){nocache_headers();echo self::accountBar().'<main class="df-access-card"><h1>Invitation prepared</h1><p>Deliver this single-use link privately to the intended existing account. No email was sent.</p><p><a href="'.esc_url(add_query_arg('df_view','invitation',home_url('/')).'#invite='.$result['token']).'">Private invitation link</a></p><p>If scripts are unavailable, sign in then paste this code on the invitation page: <code>'.esc_html($result['token']).'</code></p></main>';exit;}
  wp_safe_redirect($url);exit;
 }
 public function process(string $action,array $p):array|\WP_Error {
  if($action===Authentication::ACTION)return Authentication::process($p);
  if(!in_array($action,self::ACTIONS,true)||!is_user_logged_in()||!is_string($p['_wpnonce']??null)||!wp_verify_nonce($p['_wpnonce'],$action)||strlen((string)wp_json_encode($p))>16384)return Policy::error('confirmation');
  if($action==='digiforge_portal_continue'){return Authentication::continueTo($p['return_to']??'');}
  if($action==='digiforge_portal_invitation_accept')return is_string($p['invite']??null)&&is_string($p['request_id']??null)?Invitations::accept($p['invite'],$p['request_id']):Policy::error('typed');
  if($action==='digiforge_portal_owner_revoke')return ($p['confirm']??null)==='CONFIRM_DIGIFORGE_ONLY'&&($p['scope']??null)===GlobalOwnerAuthority::SCOPE&&is_string($p['request_id']??null)?OwnerBootstrap::revoke($p['request_id']):Policy::error('confirmation');
  if($action==='digiforge_portal_owner_renew')return ($p['confirm']??null)==='CONFIRM_DIGIFORGE_ONLY'&&($p['scope']??null)===GlobalOwnerAuthority::SCOPE&&is_string($p['request_id']??null)?OwnerBootstrap::renew($p['request_id']):Policy::error('confirmation');
  if(in_array($action,['digiforge_portal_owner_bootstrap','digiforge_portal_owner_recover'],true))return ($p['confirm']??null)==='CONFIRM_DIGIFORGE_ONLY'&&($p['scope']??null)===GlobalOwnerAuthority::SCOPE&&is_string($p['request_id']??null)?($action==='digiforge_portal_owner_recover'?OwnerBootstrap::recover('',$p['request_id']):OwnerBootstrap::enroll('',$p['request_id'])):Policy::error('confirmation');
  return Panel::process($action,$p);
 }
}
