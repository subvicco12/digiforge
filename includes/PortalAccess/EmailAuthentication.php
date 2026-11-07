<?php
declare(strict_types=1);
namespace DigiForge\PortalAccess;
/** Interactive email verification only. No account creation or authority issuance. */
final class EmailAuthentication {
 public static function key(string $selector):string{return 'digiforge_portal_email_'.hash('sha256',$selector);}
 private static function error(int $status=403):\WP_Error{return new \WP_Error('digiforge_portal_email_verification','Unable to verify. Request a new code or try again later.',['status'=>$status]);}
 private static function identity(\WP_User $user):string{return hash_hmac('sha256',$user->ID.':'.strtolower($user->user_email).':'.$user->user_pass,wp_salt('auth'));}
 private static function invitationReturn(string $return):bool {$q=[];parse_str((string)wp_parse_url($return,PHP_URL_QUERY),$q);return ($q['df_view']??null)==='invitation';}
 private static function browser():string{return hash('sha256',Authentication::browserChallenge());}
 public static function process(array $p):array|\WP_Error {
  $ip=is_string($_SERVER['REMOTE_ADDR']??null)?$_SERVER['REMOTE_ADDR']:'unknown';
  return ($p['step']??'request')==='verify'?self::verify($p,$ip):self::request($p,$ip);
 }
 private static function request(array $p,string $ip):array|\WP_Error {
  if(!is_string($p['email']??null)||strlen($p['email'])>254||!is_email($p['email']))return self::error();$email=strtolower(trim($p['email']));
  if(!Limiter::reserve('email_send_ip:'.$ip,10)||!Limiter::reserve('email_send_account:'.$email,3)||!Limiter::reserve('email_send_global',100))return self::error(429);
  $selector=bin2hex(random_bytes(24));$result=['selector'=>$selector,'verification_required'=>true,'return_to'=>Authentication::safeReturn($p['return_to']??'')];$user=get_user_by('email',$email);if(!$user)return $result;
  $profile=Store::read($user->ID);if(is_wp_error($profile)||(is_array($profile)&&!$profile['enabled']&&!self::invitationReturn($result['return_to'])&&!Authentication::ownerSetupReturn($profile,$result['return_to'])))return $result;
  $code=str_pad((string)random_int(0,99999999),8,'0',STR_PAD_LEFT);$state=['actor'=>$user->ID,'identity'=>self::identity($user),'browser'=>self::browser(),'generation'=>$profile['generation']??0,'expires'=>time()+600,'attempts'=>0,'code'=>hash_hmac('sha256',$selector.':'.$code,wp_salt('auth')),'return_to'=>$result['return_to']];
  global $wpdb;$key=self::key($selector);if($wpdb->insert($wpdb->options,['option_name'=>$key,'option_value'=>wp_json_encode($state),'autoload'=>'no'])!==1)return self::error(503);
  // No code, email or selector goes into logs or URL query strings. A mail failure
  // invalidates the challenge but keeps the public response account-neutral.
  if(!wp_mail($user->user_email,'Your DigiForge sign-in code',"Your DigiForge sign-in code is: {$code}\n\nEnter it in the browser where you requested it. It expires in 10 minutes and works once. If you did not request this, ignore this message. Never share this code.")){
   $wpdb->delete($wpdb->options,['option_name'=>$key]);\DigiForge\Security\Logger::write('portal_email_delivery_failed',[],'portal_auth','delivery');
  }
  // Bounded housekeeping affects expired authentication challenges only.
  $rows=$wpdb->get_results($wpdb->prepare("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT 100",$wpdb->esc_like('digiforge_portal_email_').'%'),ARRAY_A);
  foreach($rows?:[] as $row){$s=json_decode($row['option_value'],true);if(is_array($s)&&is_int($s['expires']??null)&&$s['expires']<time())$wpdb->delete($wpdb->options,['option_name'=>$row['option_name'],'option_value'=>$row['option_value']]);}
  return $result;
 }
 private static function verify(array $p,string $ip):array|\WP_Error {
  if(!is_string($p['selector']??null)||preg_match('/^[a-f0-9]{48}$/D',$p['selector'])!==1||!is_string($p['code']??null)||preg_match('/^[0-9]{8}$/D',$p['code'])!==1)return self::error();
  if(!Limiter::reserve('email_verify_ip:'.$ip,30))return self::error(429);
  global $wpdb;$key=self::key($p['selector']);$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key));if(!is_string($raw)||strlen($raw)>4096||$wpdb->last_error!=='')return self::error();$s=json_decode($raw,true);
  if(!is_array($s)||!is_int($s['actor']??null)||!is_int($s['expires']??null)||$s['expires']<=time()||$s['expires']>time()+600||!is_int($s['attempts']??null)||$s['attempts']>=5||!is_string($s['browser']??null)||!hash_equals($s['browser'],self::browser()))return self::error();
  // Atomic compare-and-swap reserves every attempt, including failures. Parallel
  // readers cannot both verify one challenge or evade its five-attempt budget.
  $next=$s;$next['attempts']++;$encoded=(string)wp_json_encode($next);if($wpdb->update($wpdb->options,['option_value'=>$encoded],['option_name'=>$key,'option_value'=>$raw])!==1)return self::error();
  if(!is_string($s['code']??null)||!hash_equals($s['code'],hash_hmac('sha256',$p['selector'].':'.$p['code'],wp_salt('auth'))))return self::error();
  $user=get_userdata($s['actor']);$profile=Store::read($s['actor']);if(!$user||!is_string($s['identity']??null)||!hash_equals($s['identity'],self::identity($user))||is_wp_error($profile)||(is_array($profile)&&!$profile['enabled']&&!self::invitationReturn($s['return_to']??'')&&!Authentication::ownerSetupReturn($profile,$s['return_to']??''))||($s['generation']??null)!==($profile['generation']??0))return self::error();
  $allowed=apply_filters('wp_authenticate_user',$user,'');if(is_wp_error($allowed)||!($allowed instanceof \WP_User)||$allowed->ID!==$user->ID||(is_multisite()&&is_user_spammy($user)))return self::error();
  // Consume before any session can exist, and fail closed on audit/storage error.
  if($wpdb->delete($wpdb->options,['option_name'=>$key,'option_value'=>$encoded])!==1||!\DigiForge\Security\Logger::write('portal_email_verified',['actor_id'=>$user->ID],'portal_auth',(string)$user->ID))return self::error(503);
  $priorActor=get_current_user_id();$priorToken=wp_get_session_token();
  $capture=static function($cookie,$expire,$expiration,$actor,$scheme,$token){$_COOKIE[LOGGED_IN_COOKIE]=$cookie;};add_action('set_logged_in_cookie',$capture,10,6);
  try{wp_set_current_user($user->ID);wp_set_auth_cookie($user->ID,false,is_ssl());}finally{remove_action('set_logged_in_cookie',$capture,10);}
  $token=wp_get_session_token();$manager=\WP_Session_Tokens::get_instance($user->ID);$session=$manager->get($token);if($token===''||!is_array($session)){wp_clear_auth_cookie();wp_set_current_user(0);return self::error(503);}
  $session['digiforge_email_verified_at']=time();$session['digiforge_email_identity']=self::identity($user);$manager->update($token,$session);do_action('wp_login',$user->user_login,$user);
  $r=Authentication::continueTo($s['return_to']);if(is_wp_error($r)){$manager->destroy($token);wp_clear_auth_cookie();unset($_COOKIE[LOGGED_IN_COOKIE],$_COOKIE[Session::name()]);wp_set_current_user(0);return $r;}if($priorActor>0&&$priorToken!==''&&$priorToken!==$token)\WP_Session_Tokens::get_instance($priorActor)->destroy($priorToken);return ['actor_id'=>$user->ID]+$r;
 }
 public static function recent(int $actor):bool {
  if($actor<1||get_current_user_id()!==$actor)return false;$u=get_userdata($actor);$s=\WP_Session_Tokens::get_instance($actor)->get(wp_get_session_token());return $u&&is_array($s)&&is_int($s['digiforge_email_verified_at']??null)&&$s['digiforge_email_verified_at']<=time()&&$s['digiforge_email_verified_at']>time()-600&&is_string($s['digiforge_email_identity']??null)&&hash_equals(self::identity($u),$s['digiforge_email_identity']);
 }
 public static function verificationForm(string $selector):string {
  return '<main class="df-access-card"><h1>Check your email</h1><p>If this is an eligible existing account, we sent an eight-digit code. Enter it in this browser within ten minutes.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field(Authentication::ACTION,'_wpnonce',true,false).'<input type="hidden" name="action" value="'.esc_attr(Authentication::ACTION).'"><input type="hidden" name="step" value="verify"><input type="hidden" name="selector" value="'.esc_attr($selector).'"><input type="hidden" name="challenge" value="'.esc_attr(Authentication::browserChallenge()).'"><label>Email verification code<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{8}" maxlength="8" required></label><button>Verify and sign in</button></form><p><a href="'.esc_url(Authentication::loginUrl()).'">Request a new code</a></p></main>';
 }
}
