<?php
declare(strict_types=1);
namespace DigiForge\PortalAccess;
final class Authentication {
 public const ACTION='digiforge_portal_login';
 private static function challengeName():string{return is_ssl()?'__Host-digiforge_login_csrf':'digiforge_login_csrf';}
 public static function browserChallenge():string {
  $key=self::challengeName();$value=$_COOKIE[$key]??null;if(!is_string($value)||preg_match('/^[a-f0-9]{64}$/D',$value)!==1){$value=bin2hex(random_bytes(32));$_COOKIE[$key]=$value;if(!headers_sent())setcookie($key,$value,['expires'=>time()+900,'path'=>'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);}return $value;
 }
 public static function loginUrl(string $return=''):string{return add_query_arg(['df_auth'=>'login','redirect_to'=>self::safeReturn($return)],home_url('/'));}
 public static function safeReturn(mixed $url):string {
  if(!is_string($url)||strlen($url)>2048||str_starts_with($url,'//')||str_contains($url,"\r")||str_contains($url,"\n"))return home_url('/');$url=wp_validate_redirect($url,home_url('/'));$u=wp_parse_url($url);$h=wp_parse_url(home_url('/'));if(!$u||isset($u['user'])||isset($u['pass'])||($u['host']??'')!==($h['host']??'')||($u['scheme']??'')!==($h['scheme']??'')||($u['port']??null)!==($h['port']??null))return home_url('/');return remove_query_arg(['df_auth','df_result'],$url);
 }
 private static function failure(string $code='login_failed',int $status=403):\WP_Error{return new \WP_Error('digiforge_portal_'.$code,'Unable to sign in. Check your details or try again later.',['status'=>$status]);}
 public static function process(array $p):array|\WP_Error {
  $challenge=$_COOKIE[self::challengeName()]??null;if(!is_string($challenge)||!is_string($p['challenge']??null)||preg_match('/^[a-f0-9]{64}$/D',$challenge)!==1||!hash_equals($challenge,$p['challenge']))return self::failure();
  if(!is_string($p['_wpnonce']??null)||!wp_verify_nonce($p['_wpnonce'],self::ACTION)||!is_string($p['login']??null)||!is_string($p['password']??null)||strlen($p['login'])>254||strlen($p['password'])>4096||$p['login']===''||$p['password']==='')return self::failure();
  $ip=is_string($_SERVER['REMOTE_ADDR']??null)?$_SERVER['REMOTE_ADDR']:'unknown';if(!Limiter::reserve('login_ip:'.$ip,30)||!Limiter::reserve('login_actor:'.$ip.':'.strtolower(trim($p['login'])),5))return self::failure('login_throttled',429);
  $capture=static function($cookie){$_COOKIE[LOGGED_IN_COOKIE]=$cookie;};add_action('set_logged_in_cookie',$capture);try{$user=wp_signon(['user_login'=>trim($p['login']),'user_password'=>$p['password'],'remember'=>false],is_ssl());}finally{remove_action('set_logged_in_cookie',$capture);}if(is_wp_error($user))return self::failure();wp_set_current_user($user->ID);
  // wp_signon owns the native cookie. Also make it available during this same request.
  if(wp_get_session_token()===''){return ['actor_id'=>$user->ID,'return_to'=>self::loginUrl(self::safeReturn($p['return_to']??'')),'continue_required'=>true];}
  if(!\DigiForge\Security\Logger::write('portal_login',['actor_id'=>$user->ID],'portal_auth',(string)$user->ID))return self::failure('login_audit',503);$r=Session::start();if(is_wp_error($r))return $r;return ['actor_id'=>$user->ID,'return_to'=>self::safeReturn($p['return_to']??'')];
 }
 public static function render():string {
  $return=self::safeReturn($_GET['redirect_to']??'');$html='<main class="df-access-card"><h1>DigiForge sign in</h1><p>Use your existing WordPress account. Shop access requires a separate reviewed assignment.</p>';
  if(isset($_GET['df_result']))$html.='<p role="alert">Unable to complete that request. Check your access or try again later.</p>';
  $action=is_user_logged_in()?'digiforge_portal_continue':self::ACTION;$html.='<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field($action,'_wpnonce',true,false).'<input type="hidden" name="action" value="'.esc_attr($action).'"><input type="hidden" name="return_to" value="'.esc_attr($return).'">';
  if(!is_user_logged_in())$html.='<input type="hidden" name="challenge" value="'.esc_attr(self::browserChallenge()).'">';
  if(!is_user_logged_in())$html.='<label>Username or email<input name="login" autocomplete="username" required maxlength="254"></label><label>Password<input type="password" name="password" autocomplete="current-password" required maxlength="4096"></label>';
  $html.='<button type="submit">'.(is_user_logged_in()?'Continue to DigiForge':'Sign in').'</button></form><p><a href="'.esc_url(wp_lostpassword_url(self::loginUrl($return))).'">Reset your WordPress password</a></p>';
  if(is_user_logged_in())$html.='<p><a href="'.esc_url(wp_logout_url(self::loginUrl())).'">Sign out</a></p>';return $html.'</main>';
 }
}
