<?php
declare(strict_types=1);
namespace DigiForge\PortalAccess;
use DigiForge\Security\{ShopAccessAuthority,ShopAccessPolicy};
/** Self-account enrollment preflight. No grant, key, account or configuration writes. */
final class OwnerReadiness {
 public static function inspect(string $shop):array {
  $result=['state'=>'LOGIN_REQUIRED','shop'=>$shop,'account_id'=>get_current_user_id(),'intended_email'=>Owner::EMAIL,'ready_to_enroll'=>false,'native_access_checked'=>false,'production_authority'=>false];
  if($result['account_id']<1)return $result;
  if(!ShopAccessPolicy::validShop($shop))return array_replace($result,['state'=>'INVALID_SHOP']);
  $u=wp_get_current_user();if(strtolower($u->user_email)!==Owner::EMAIL)return array_replace($result,['state'=>'ACCOUNT_IDENTITY_MISMATCH']);
  if(!user_can($u,'manage_digiforge')||!user_can($u,'manage_digiforge_settings'))return array_replace($result,['state'=>'WORDPRESS_CAPABILITY_MISSING']);
  $records=ShopAccessAuthority::records();
  if(is_wp_error($records))return array_replace($result,['state'=>match($records->get_error_code()){'digiforge_shop_authority_missing'=>'SIGNED_AUTHORITY_MISSING','digiforge_shop_authority_signature'=>'SIGNED_AUTHORITY_SIGNATURE_INVALID',default=>'SIGNED_AUTHORITY_INVALID_OR_EXPIRED'}]);
  // Use the enrollment path's canonical authority resolution, including record order.
  $resolved=Owner::signed($u->ID,$shop);
  if(is_wp_error($resolved))return array_replace($result,['state'=>'OWNER_SHOP_SCOPE_MISSING']);
  $authority=$resolved['record'];
  if($authority['expires_at']<=time()+1||array_diff(['manage_digiforge','manage_digiforge_settings'],$authority['caps'])!==[])return array_replace($result,['state'=>'OWNER_SHOP_SCOPE_MISSING']);
  $s=Store::read($u->ID);if(is_wp_error($s))return array_replace($result,['state'=>'PROFILE_EVIDENCE_UNAVAILABLE']);
  $hash=$resolved['hash'];
  if($s!==null&&($s['role']!=='Primary Owner'||$s['revision']>=1000))return array_replace($result,['state'=>'EXISTING_MANAGED_PROFILE_REQUIRES_RECOVERY']);
  $state=$s!==null&&$s['enabled']&&($s['authority_hashes'][$shop]??null)===$hash&&array_diff(['manage_digiforge','manage_digiforge_settings'],$s['shops'][$shop]??[])===[]?'ENROLLED':'READY_TO_ENROLL';
  return array_replace($result,['state'=>$state,'ready_to_enroll'=>true,'authority_expires_at'=>$authority['expires_at']]);
 }
 public static function message(string $state):string {
  return match($state){'LOGIN_REQUIRED'=>'Sign in with your existing WordPress account.','ACCOUNT_IDENTITY_MISMATCH'=>'Use the independently verified existing primary-owner account. An email match alone never grants authority.','WORDPRESS_CAPABILITY_MISSING'=>'The existing WordPress account needs reviewed native DigiForge capabilities; this screen does not assign them.','SIGNED_AUTHORITY_MISSING'=>'The trusted host must restore the approved Ed25519 public trust key and signed owner envelope.','SIGNED_AUTHORITY_SIGNATURE_INVALID'=>'The signed envelope does not verify against the configured public trust key.','SIGNED_AUTHORITY_INVALID_OR_EXPIRED'=>'The signed authority is invalid or expired; the trusted custodian must review it.','OWNER_SHOP_SCOPE_MISSING'=>'No current signed OWNER record binds this account and the selected shop.','PROFILE_EVIDENCE_UNAVAILABLE'=>'Enrollment history is unavailable or inconsistent; do not bypass its integrity checks.','EXISTING_MANAGED_PROFILE_REQUIRES_RECOVERY'=>'An existing managed profile cannot be promoted by this bootstrap. Use separately approved owner recovery.','ENROLLED'=>'An audited owner profile matches current signed authority. Native grants and the current session are checked when opening user management.','READY_TO_ENROLL'=>'Verified signed owner scope is present. Review the account, shop and capabilities, then explicitly confirm enrollment.',default=>'Select a valid DigiForge shop.'};
 }
}
