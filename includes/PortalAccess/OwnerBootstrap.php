<?php
declare(strict_types=1);
namespace DigiForge\PortalAccess;
use DigiForge\Core\Capabilities;
use DigiForge\Security\{ShopAccessPolicy,ShopAccessRepository,Logger};
/** Explicit four-shop enrollment. No key generation, host changes or external authority. */
final class OwnerBootstrap {
 public const KEY='digiforge_portal_global_owner_bootstrap_claim';
 public static function configured():bool{return defined('DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SCOPE')&&DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SCOPE===GlobalOwnerAuthority::SCOPE&&defined('DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SITE')&&is_string(DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SITE)&&hash_equals(DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SITE,home_url('/'));}
 public static function enroll(string $code,string $request):array|\WP_Error{return self::run($code,$request,'ENROLL');}
 public static function recover(string $code,string $request):array|\WP_Error{return self::run($code,$request,'RECOVER');}
 public static function renew(string $request):array|\WP_Error{return self::run('',$request,'RENEW');}
 /** Non-mutating scope for a separate trusted recovery approval. Contains no setup secrets. */
 public static function recoveryPlan():array|\WP_Error {
  $profile=Store::read(1);$a=GlobalOwnerAuthority::current();if(is_wp_error($profile)||!is_array($profile)||$profile['role']!==GlobalOwnerAuthority::ROLE||GlobalOwnerAuthority::active($profile)||is_wp_error($a))return Policy::error('global_recovery_state');
  if(self::verifyClaim($profile)!==true)return Policy::error('global_recovery_claim');$claimHash=$profile['global_claim_hash'];
  $native=[];foreach(ShopAccessPolicy::canonicalShops() as $shop){$s=(new ShopAccessRepository())->read($shop);if(is_wp_error($s))return $s;$native[$shop]=hash('sha256',(string)wp_json_encode($s));}
  $scope=['site'=>home_url('/'),'actor_id'=>1,'profile_hash'=>Store::hash($profile),'authority_hash'=>$a['hash'],'claim_sha256'=>$claimHash,'native_state_hashes'=>$native];
  return ['scope_sha256'=>hash('sha256',(string)wp_json_encode($scope)),'scope'=>$scope,'automatic_retry_permitted'=>false,'external_execution_authorized'=>false];
 }
 public static function verifyClaim(array $profile):true|\WP_Error {
  $key=$profile['global_claim_key']??null;$hash=$profile['global_claim_hash']??null;if(!is_string($key)||preg_match('/^'.preg_quote(self::KEY,'/').'(_(recovery|renew)_[a-f0-9]{64})?$/D',$key)!==1||!is_string($hash))return Policy::error('global_claim_evidence');
  global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key));if(!is_string($raw)||strlen($raw)>4096||$wpdb->last_error!==''||!hash_equals($hash,hash('sha256',$raw)))return Policy::error('global_claim_evidence');
  $rows=$wpdb->get_col($wpdb->prepare("SELECT context FROM ".\DigiForge\Database\Tables::audit_log()." WHERE object_type='portal_auth' AND object_id='1' AND event_type='portal_global_owner_claimed' AND actor_id=1 AND BINARY context=BINARY %s LIMIT 2",$raw));
  return $wpdb->last_error===''&&is_array($rows)&&count($rows)===1&&$rows[0]===$raw?true:Policy::error('global_claim_evidence');
 }
 /** Narrow native-repository admission; no public route can supply this staged claim context. */
 public static function nativeMutationAllowed(string $shop,string $request,array $input,?string $revoke):bool {
  if(get_current_user_id()!==1||!ShopAccessPolicy::validShop($shop))return false;$profile=Store::read(1);if(is_wp_error($profile)||!is_array($profile))return false;
  if(!isset($input['owner_approval']))return GlobalOwnerAuthority::active($profile)&&Session::valid($profile);
  if($profile['role']!==GlobalOwnerAuthority::ROLE||$profile['enabled']||($profile['bootstrap_pending']??false)!==true||!EmailAuthentication::recent(1)||self::verifyClaim($profile)!==true||!is_string($input['global_claim_hash']??null)||!hash_equals($profile['global_claim_hash'],$input['global_claim_hash']))return false;
  $a=GlobalOwnerAuthority::current();if(is_wp_error($a)||$a['hash']!==$profile['global_authority_hash']||$input['owner_approval']!==$a['source_approval_hash']||($input['caps']??null)!==Capabilities::ALL||($input['subject']??null)!==1||!is_int($input['expires_at']??null)||$input['expires_at']<=time()||$input['expires_at']>min(time()+3600,$a['expires_at'])||$revoke!==null)return false;
  global $wpdb;$raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$profile['global_claim_key']));$claim=is_string($raw)?json_decode($raw,true):null;
  return is_array($claim)&&is_string($claim['request_id']??null)&&hash_equals(hash('sha256',$claim['request_id'].':global:'.$shop),$request);
 }
 private static function run(string $code,string $request,string $purpose):array|\WP_Error {
  $recovery=$purpose==='RECOVER';$renew=$purpose==='RENEW';$actor=get_current_user_id();$u=get_userdata($actor);$hashName=$recovery?'DIGIFORGE_PORTAL_OWNER_RECOVERY_SHA256':'DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SHA256';
  if($actor!==1||!$u||strtolower($u->user_email)!==Owner::EMAIL||!user_can(1,'manage_options')||!EmailAuthentication::recent(1)||!self::configured()||preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$request)!==1||(!$renew&&(preg_match('/^[a-f0-9]{64}$/D',$code)!==1||!defined($hashName)||!is_string(constant($hashName))||preg_match('/^[a-f0-9]{64}$/D',constant($hashName))!==1)))return Policy::error('global_bootstrap');
  if(!Limiter::reserve('global_owner_bootstrap:1',5)||(!$renew&&!hash_equals(constant($hashName),hash('sha256',$code))))return Policy::error('global_bootstrap');
  $a=GlobalOwnerAuthority::current();if(is_wp_error($a))return $a;$old=Store::read(1);if(is_wp_error($old))return $old;
  $scope=$a['hash'];
  if($recovery){$plan=self::recoveryPlan();if(is_wp_error($plan)||!defined('DIGIFORGE_PORTAL_OWNER_RECOVERY_SCOPE_SHA256')||!is_string(DIGIFORGE_PORTAL_OWNER_RECOVERY_SCOPE_SHA256)||!hash_equals(DIGIFORGE_PORTAL_OWNER_RECOVERY_SCOPE_SHA256,$plan['scope_sha256']))return Policy::error('global_recovery_approval');$scope=$plan['scope_sha256'];}
  elseif($renew){if(!is_array($old)||!GlobalOwnerAuthority::active($old,true))return Policy::error('global_renewal_denied');$scope=Store::hash($old);}
  elseif($old!==null&&($old['role']!=='Primary Owner'||!$old['enabled']||($old['bootstrap_pending']??false)===true))return Policy::error('global_owner_exists_or_pending');
  if(($old['revision']??0)>997)return Policy::error('history_capacity');
  global $wpdb;$wpdb->last_error='';$keys=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 101",$wpdb->esc_like('digiforge_portal_profile_').'%'));
  if($wpdb->last_error!==''||!is_array($keys)||count($keys)>100)return Policy::error('owner_conflict');
  foreach($keys as $key){if(!preg_match('/^digiforge_portal_profile_([1-9][0-9]*)$/D',$key,$m))return Policy::error('owner_conflict');$s=Store::read((int)$m[1]);if(is_wp_error($s)||((int)$m[1]!==1&&in_array($s['role']??null,['Primary Owner',GlobalOwnerAuthority::ROLE],true)))return Policy::error('owner_conflict');}
  foreach(Capabilities::ALL as $cap)if(!user_can(1,$cap))return Policy::error('ordinary_capability');
  $role=null;foreach($u->roles as $candidate){$r=get_role($candidate);if($r&&array_diff(Capabilities::ALL,array_keys(array_filter($r->capabilities)))===[]){$role=$candidate;break;}}if($role===null)return Policy::error('ordinary_role');
  $states=[];$hashes=[];$shops=[];foreach($a['records'] as $shop=>$entry){$state=(new ShopAccessRepository())->read($shop);if(is_wp_error($state))return $state;if($recovery&&($plan['scope']['native_state_hashes'][$shop]??null)!==hash('sha256',(string)wp_json_encode($state)))return Policy::error('global_recovery_state_changed');$states[$shop]=$state;$hashes[$shop]=$entry['hash'];$shops[$shop]=Capabilities::ALL;}
  $claimKey=$recovery?self::KEY.'_recovery_'.$scope:($renew?self::KEY.'_renew_'.$scope:self::KEY);
  $claim=['actor_id'=>1,'scope'=>GlobalOwnerAuthority::SCOPE,'site'=>home_url('/'),'authority_hash'=>$a['hash'],'request_id'=>$request,'purpose'=>$purpose,'claimed_at'=>time(),'prior_profile_hash'=>$old===null?null:Store::hash($old),'approval_scope'=>$scope];
  $claimRaw=(string)wp_json_encode($claim);
  $pending=['actor_id'=>1,'role'=>GlobalOwnerAuthority::ROLE,'enabled'=>false,'bootstrap_pending'=>true,'revision'=>($old['revision']??0)+1,'generation'=>($old['generation']??0)+1,'shops'=>$shops,'authority_hashes'=>$hashes,'global_authority_hash'=>$a['hash'],'global_grants'=>[],'global_claim_key'=>$claimKey,'global_claim_hash'=>hash('sha256',$claimRaw)];
  // One transaction binds claim, claim audit, restrictive profile and its event.
  $saved=Store::save($pending,$old['revision']??0,hash('sha256',$request.':global-pending'),static function()use($wpdb,$claimKey,$claimRaw,$claim,$states,$a,$old,$renew){
   foreach($states as $shop=>$expected){$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE",'digiforge_shop_access_state_'.$shop));$locked=$raw===null?['revision'=>0,'grants'=>[]]:(is_string($raw)?json_decode($raw,true):null);if($wpdb->last_error!==''||$locked!==$expected)return false;}
   $current=GlobalOwnerAuthority::current();if(is_wp_error($current)||$current['hash']!==$a['hash']||($renew&&!GlobalOwnerAuthority::active($old,true)))return false;
   return $wpdb->insert($wpdb->options,['option_name'=>$claimKey,'option_value'=>$claimRaw,'autoload'=>'no'])===1&&Logger::write('portal_global_owner_claimed',$claim,'portal_auth','1');
  });if(is_wp_error($saved))return $saved;
  $grants=[];$committed=[];$expiry=min(time()+3600,$a['expires_at']);
  foreach($states as $shop=>$state){
   $current=GlobalOwnerAuthority::current();if(is_wp_error($current)||$current['hash']!==$a['hash'])return Policy::error('global_authority_changed');
   $repo=new ShopAccessRepository();$result=$repo->enrollOwner($shop,$role,Capabilities::ALL,$expiry,$a['source_approval_hash'],hash('sha256',$request.':global:'.$shop),$state['revision'],$pending['global_claim_hash']);if(is_wp_error($result))return $result;$grants[$shop]=$result['grant_id'];$committed[$shop]=$repo->read($shop);if(is_wp_error($committed[$shop])||$committed[$shop]['revision']!==$state['revision']+1)return Policy::error('global_state_changed');
  }
  $enabled=$pending;$enabled['enabled']=true;$enabled['bootstrap_pending']=false;$enabled['global_grants']=$grants;$enabled['revision']++;$enabled['generation']++;
  if(!GlobalOwnerAuthority::active($enabled))return Policy::error('global_grant_evidence');
  $saved=Store::save($enabled,$pending['revision'],hash('sha256',$request.':global-active'),static function()use($wpdb,$committed,$enabled){
   foreach($committed as $shop=>$expected){$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE",'digiforge_shop_access_state_'.$shop));if($wpdb->last_error!==''||!is_string($raw)||json_decode($raw,true)!==$expected)return false;$now=(new ShopAccessRepository())->read($shop);if(is_wp_error($now)||$now!==$expected)return false;}
   return GlobalOwnerAuthority::active($enabled);
  });if(is_wp_error($saved))return $saved;
  $started=Session::start();return is_wp_error($started)?$started:$saved;
 }
 public static function render():string {
  $h='<section class="df-access-card"><h1>Global Primary Owner enrollment</h1><p>Platform administration covers Digital Products, Personalized POD, Standard POD and Personalized Jewelry. It never enables inactive shops or external execution. Verify your email and enter the one-time enrollment code supplied privately by the trusted setup operator. You do not handle signing keys.</p>';
  if(get_current_user_id()!==1||!get_userdata(1)||strtolower(get_userdata(1)->user_email)!==Owner::EMAIL)return $h.'<p role="alert">The verified existing primary-owner account #1 is required.</p></section>';
  $r=OwnerReadiness::inspect('personalized_pod');$h.='<p role="status">'.esc_html($r['state']).': '.esc_html(OwnerReadiness::message($r['state'])).'</p>';
  if($r['state']==='GLOBAL_ENROLLED')return $h.'<p>Global administration is enrolled. User Management retains exact delegated shop/module scope.</p></section>';
  if(!EmailAuthentication::recent(1))return $h.'<a href="'.esc_url(Authentication::loginUrl(add_query_arg('df_view','owner',home_url('/')))).'">Verify email</a></section>';
  $profile=Store::read(1);if(is_array($profile)&&GlobalOwnerAuthority::active($profile,true))return $h.self::renewForm().'</section>';
  if(in_array($r['state'],['GLOBAL_ENROLLMENT_INTERRUPTED','GLOBAL_ACCESS_REVOKED_OR_EXPIRED'],true)){
   $plan=self::recoveryPlan();if(is_wp_error($plan))return $h.'<p>Recovery evidence unavailable. No automatic retry.</p></section>';
   $h.='<p>Trusted recovery approval must bind scope <code>'.esc_html($plan['scope_sha256']).'</code>. Ordinary enrollment cannot resume this claim.</p>';
   if(!defined('DIGIFORGE_PORTAL_OWNER_RECOVERY_SCOPE_SHA256')||DIGIFORGE_PORTAL_OWNER_RECOVERY_SCOPE_SHA256!==$plan['scope_sha256']||!defined('DIGIFORGE_PORTAL_OWNER_RECOVERY_SHA256'))return $h.'</section>';
   return $h.self::form(true).'</section>';
  }
  return $h.($r['state']==='GLOBAL_READY_TO_ENROLL'?self::form(false):'<p>Trusted host setup and a valid signed global authority are required. Email alone never grants ownership.</p>').'</section>';
 }
 private static function renewForm():string {
  $action='digiforge_portal_owner_renew';return '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field($action,'_wpnonce',true,false).'<input type="hidden" name="action" value="'.$action.'"><input type="hidden" name="scope" value="GLOBAL_PRIMARY_OWNER"><input type="hidden" name="request_id" value="'.esc_attr(wp_generate_uuid4()).'"><label><input type="checkbox" name="confirm" value="CONFIRM_DIGIFORGE_ONLY" required>Renew bounded native assignments under the same current, unrevoked signed global authority.</label><button>Renew global administrative access</button></form>';
 }
 private static function form(bool $recovery):string {
  $action=$recovery?'digiforge_portal_owner_recover':'digiforge_portal_owner_bootstrap';return '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field($action,'_wpnonce',true,false).'<input type="hidden" name="action" value="'.$action.'"><input type="hidden" name="scope" value="GLOBAL_PRIMARY_OWNER"><input type="hidden" name="request_id" value="'.esc_attr(wp_generate_uuid4()).'"><label>One-time '.($recovery?'recovery':'enrollment').' code<input type="password" name="code" maxlength="64" autocomplete="off" required></label><label><input type="checkbox" name="confirm" value="CONFIRM_DIGIFORGE_ONLY" required>I confirm global DigiForge administration only.</label><button>Confirm '.($recovery?'reviewed recovery':'global enrollment').'</button></form>';
 }
}
