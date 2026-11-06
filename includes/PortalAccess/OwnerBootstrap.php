<?php
declare(strict_types=1);
namespace DigiForge\PortalAccess;
/** One-time host-approved enrollment. Never issues or substitutes signed authority. */
final class OwnerBootstrap {
 public const KEY='digiforge_portal_owner_bootstrap_claim';
 public static function enroll(string $code,string $request):array|\WP_Error {
  $actor=get_current_user_id();$u=get_userdata($actor);
  if($actor!==1||!$u||!user_can($actor,'manage_options')||!defined('DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SITE')||!is_string(DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SITE)||!hash_equals(DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SITE,home_url('/'))||strtolower($u->user_email)!==Owner::EMAIL||!EmailAuthentication::recent($actor)||preg_match('/^[a-f0-9]{64}$/D',$code)!==1||!defined('DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SHA256')||!is_string(DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SHA256)||preg_match('/^[a-f0-9]{64}$/D',DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SHA256)!==1||!preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$request))return Policy::error('owner_bootstrap');
  if(!Limiter::reserve('owner_bootstrap:'.$actor,5)||!hash_equals(DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SHA256,hash('sha256',$code)))return Policy::error('owner_bootstrap');
  $authority=Owner::signed($actor,'personalized_pod');$old=Store::read($actor);if(is_wp_error($authority)||is_wp_error($old)||$old!==null)return Policy::error('owner_bootstrap');
  $records=\DigiForge\Security\ShopAccessAuthority::records();if(is_wp_error($records))return $records;$owners=0;
  foreach($records as $record)if($record['shop']==='personalized_pod'&&$record['authority']==='OWNER'){if($record['actor_id']!==1)return Policy::error('owner_conflict');$owners++;}if($owners!==1)return Policy::error('owner_conflict');
  global $wpdb;$wpdb->last_error='';$profiles=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 101",$wpdb->esc_like('digiforge_portal_profile_').'%'));if($wpdb->last_error!==''||!is_array($profiles)||count($profiles)>100)return Policy::error('owner_conflict');
  foreach($profiles as $key){if(!preg_match('/^digiforge_portal_profile_([1-9][0-9]*)$/D',$key,$m))return Policy::error('owner_conflict');$profile=Store::read((int)$m[1]);if(is_wp_error($profile)||($profile['role']??null)==='Primary Owner')return Policy::error('owner_conflict');}
  $caps=$authority['record']['caps'];foreach($caps as $cap)if(!user_can($actor,$cap))return Policy::error('ordinary_capability');
  $role=null;foreach($u->roles as $candidate){$wpRole=get_role($candidate);if($wpRole&&array_diff($caps,array_keys(array_filter($wpRole->capabilities)))===[]){$role=$candidate;break;}}if($role===null)return Policy::error('ordinary_role');
  $repo=new \DigiForge\Security\ShopAccessRepository();$state=$repo->read('personalized_pod');if(is_wp_error($state))return $state;
  // Atomic unique insert prevents concurrent enrollment and permanently consumes
  // the host approval before grants. Interrupted attempts require trusted recovery,
  // never an automatic retry or an alternate authority mechanism.
  global $wpdb;if($wpdb->insert($wpdb->options,['option_name'=>self::KEY,'option_value'=>wp_json_encode(['actor_id'=>1,'claimed_at'=>time(),'request_id'=>$request,'authority_hash'=>$authority['hash']]),'autoload'=>'no'])!==1)return Policy::error('owner_bootstrap_consumed');
  if(!\DigiForge\Security\Logger::write('portal_owner_bootstrap_claimed',['actor_id'=>1,'authority_hash'=>$authority['hash'],'request_id'=>$request],'portal_auth','1'))return Policy::error('owner_bootstrap_audit',503);
  return Owner::enroll('personalized_pod',$role,$caps,min(time()+3600,$authority['record']['expires_at']),$authority['record']['source_approval_hash'],$state['revision'],$request);
 }
 public static function render():string {
  $h='<section class="df-access-card"><h1>Primary Owner enrollment</h1><p>Verify your email, then enter the one-time enrollment code supplied privately by the trusted setup operator. You do not need to handle signing keys or authority files.</p>';
  if(get_current_user_id()!==1||!get_userdata(1)||strtolower(get_userdata(1)->user_email)!==Owner::EMAIL)return $h.'<p role="alert">The independently verified existing primary-owner account #1 is required.</p></section>';
  if(Owner::require('personalized_pod')===true)return $h.'<p role="status">Primary Owner enrolled for Shop 2. Use User Management to assign scoped access.</p></section>';
  if(!EmailAuthentication::recent(1))return $h.'<p role="alert">Sign in with a fresh email code before enrollment.</p><a href="'.esc_url(Authentication::loginUrl(add_query_arg(['df_view'=>'owner','df_shop'=>'personalized_pod'],home_url('/')))).'">Verify email</a></section>';
  if(!defined('DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SITE')||!is_string(DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SITE)||!hash_equals(DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SITE,home_url('/'))||!defined('DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SHA256')||!is_string(DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SHA256)||preg_match('/^[a-f0-9]{64}$/D',DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SHA256)!==1||is_wp_error(Owner::signed(1,'personalized_pod')))return $h.'<p role="alert">Trusted host setup is incomplete or signed authority is invalid/expired. Email verification alone cannot grant ownership.</p></section>';
  return $h.'<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('digiforge_portal_owner_bootstrap','_wpnonce',true,false).'<input type="hidden" name="action" value="digiforge_portal_owner_bootstrap"><input type="hidden" name="shop" value="personalized_pod"><input type="hidden" name="request_id" value="'.esc_attr(wp_generate_uuid4()).'"><label>One-time enrollment code<input type="password" name="code" maxlength="64" autocomplete="off" required></label><label><input type="checkbox" name="confirm" value="CONFIRM_DIGIFORGE_ONLY" required>Enroll this verified account as DigiForge Shop 2 Primary Owner.</label><button>Complete owner enrollment</button></form></section>';
 }
}
