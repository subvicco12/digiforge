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
  if(!is_string($p['_wpnonce']??null)||!wp_verify_nonce($p['_wpnonce'],self::ACTION)||strlen((string)wp_json_encode($p))>8192||isset($p['password']))return self::failure();
  return EmailAuthentication::process($p);
 }
 public static function continueTo(mixed $return):array|\WP_Error{$url=self::safeReturn($return);$q=[];parse_str(wp_parse_url($url,PHP_URL_QUERY)??'',$q);$s=Store::read(get_current_user_id());if(is_wp_error($s))return $s;if(is_array($s)&&!$s['enabled']&&($q['df_view']??null)==='invitation')return ['return_to'=>$url,'invitation_pending'=>true];$r=Session::start();return is_wp_error($r)?$r:['return_to'=>$url];}
 public static function render():string {
  $return=self::safeReturn($_GET['redirect_to']??'');$html='<main class="df-access-card"><h1>DigiForge sign in</h1><p>Sign in with a verification code sent to your existing WordPress account email. Shop access requires a separate reviewed assignment.</p>';
  if(isset($_GET['df_result']))$html.='<p role="alert">Unable to complete that request. Check your access or try again later.</p>';
  $emailFlow=!is_user_logged_in()||!EmailAuthentication::recent(get_current_user_id());$action=$emailFlow?self::ACTION:'digiforge_portal_continue';$html.='<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field($action,'_wpnonce',true,false).'<input type="hidden" name="action" value="'.esc_attr($action).'"><input type="hidden" name="return_to" value="'.esc_attr($return).'">';
  if($emailFlow)$html.='<input type="hidden" name="challenge" value="'.esc_attr(self::browserChallenge()).'">';
  if($emailFlow)$html.='<label>Email address<input type="email" name="email" autocomplete="email" required maxlength="254"></label>';
  $html.='<button type="submit">'.($emailFlow?'Email my sign-in code':'Continue to DigiForge').'</button></form><p><a href="'.esc_url(wp_lostpassword_url(self::loginUrl($return))).'">Reset your WordPress password</a></p>';
  if(is_user_logged_in())$html.='<p><a href="'.esc_url(wp_logout_url(self::loginUrl())).'">Sign out</a></p>';return $html.'</main>';
 }
}
