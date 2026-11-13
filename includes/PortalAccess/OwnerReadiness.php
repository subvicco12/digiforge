<?php
declare(strict_types=1);
namespace DigiForge\PortalAccess;
use DigiForge\Security\ShopAccessPolicy;
/** Self-account global authority preflight; SELECT/file verification only, no audit writes. */
final class OwnerReadiness {
 public static function inspect(string $shop):array {
  $r=['state'=>'LOGIN_REQUIRED','shop'=>$shop,'account_id'=>get_current_user_id(),'intended_email'=>Owner::EMAIL,'ready_to_enroll'=>false,'production_authority'=>false];
  if($r['account_id']<1)return $r;
  if(!ShopAccessPolicy::validShop($shop))return array_replace($r,['state'=>'INVALID_SHOP']);
  $u=wp_get_current_user();if($u->ID!==1||strtolower($u->user_email)!==Owner::EMAIL)return array_replace($r,['state'=>'ACCOUNT_IDENTITY_MISMATCH']);
  if(!user_can(1,'manage_options'))return array_replace($r,['state'=>'WORDPRESS_CAPABILITY_MISSING']);
  $profile=Store::read(1);if(is_wp_error($profile))return array_replace($r,['state'=>'PROFILE_EVIDENCE_UNAVAILABLE']);
  if(is_array($profile)&&$profile['role']===GlobalOwnerAuthority::ROLE&&($profile['bootstrap_pending']??false)===true&&!$profile['enabled'])return array_replace($r,['state'=>'GLOBAL_ENROLLMENT_INTERRUPTED']);
  if(is_array($profile)&&$profile['role']===GlobalOwnerAuthority::ROLE)return array_replace($r,['state'=>GlobalOwnerAuthority::active($profile)?'GLOBAL_ENROLLED':'GLOBAL_ACCESS_REVOKED_OR_EXPIRED']);
  $a=GlobalOwnerAuthority::current();
  if(is_wp_error($a))return array_replace($r,['state'=>match($a->get_error_code()){'digiforge_shop_authority_missing'=>'SIGNED_AUTHORITY_MISSING','digiforge_shop_authority_signature'=>'SIGNED_AUTHORITY_SIGNATURE_INVALID','digiforge_global_owner_required'=>'GLOBAL_AUTHORITY_REQUIRED','digiforge_global_owner_conflict'=>'GLOBAL_AUTHORITY_CONFLICT','digiforge_global_owner_scope'=>'GLOBAL_AUTHORITY_SCOPE_MISMATCH',default=>'DATABASE_AUTHORITY_OR_APPROVAL_INVALID'}]);
  foreach($a['caps'] as $cap)if(!user_can(1,$cap))return array_replace($r,['state'=>'WORDPRESS_CAPABILITY_MISSING']);
  if($profile!==null&&($profile['role']!=='Primary Owner'||!$profile['enabled']||($profile['bootstrap_pending']??false)===true))return array_replace($r,['state'=>'EXISTING_MANAGED_PROFILE_REQUIRES_RECOVERY']);
  if(!OwnerBootstrap::configured())return array_replace($r,['state'=>'GLOBAL_HOST_SETUP_REQUIRED']);
  return array_replace($r,['state'=>'GLOBAL_READY_TO_ENROLL','ready_to_enroll'=>true,'authority_expires_at'=>$a['expires_at'],'authority_hash'=>$a['hash'],'upgrades_legacy_profile'=>$profile!==null]);
 }
 public static function message(string $state):string{return match($state){
  'LOGIN_REQUIRED'=>'Sign in using your existing WordPress account and email verification.',
  'ACCOUNT_IDENTITY_MISMATCH'=>'Global ownership is bound to independently verified existing WordPress actor1 and the approved owner email.',
  'WORDPRESS_CAPABILITY_MISSING'=>'Reviewed native WordPress administrator/module capabilities are missing. No capabilities are granted here.',
  'SIGNED_AUTHORITY_MISSING'=>'Trusted host public-key or signed-envelope configuration is missing; login itself is working independently.',
  'SIGNED_AUTHORITY_SIGNATURE_INVALID'=>'The envelope signature does not verify against the existing trusted public root.',
  'DATABASE_AUTHORITY_OR_APPROVAL_INVALID'=>'Server approval or database authority is unavailable, invalid or expired; trusted host review is required.',
  'GLOBAL_AUTHORITY_REQUIRED'=>'Legacy or partial shop authority is not global authority. A custodian-signed explicit four-shop global set is required.',
  'GLOBAL_AUTHORITY_CONFLICT'=>'Duplicate or competing OWNER authorities must be reconciled by the existing trusted custodian.',
  'GLOBAL_AUTHORITY_SCOPE_MISMATCH'=>'Signed actor/email/site/capabilities/global-set bindings do not match this installation.',
  'GLOBAL_HOST_SETUP_REQUIRED'=>'Host setup must explicitly approve this actor, exact site and GLOBAL_PRIMARY_OWNER scope.',
  'GLOBAL_ENROLLMENT_INTERRUPTED'=>'Enrollment is disabled and pending. A separately scope-bound trusted recovery approval is required; ordinary retries are denied.',
  'GLOBAL_ACCESS_REVOKED_OR_EXPIRED'=>'A global grant, signature or profile is revoked/expired. Existing sessions confer no DigiForge access.',
  'GLOBAL_ENROLLED'=>'Audited global ownership and all four live database assignments match. Operational safety controls still apply.',
  'GLOBAL_READY_TO_ENROLL'=>'Review the verified actor, all four shops and explicit administrative scope, then confirm one-time enrollment.',
  'EXISTING_MANAGED_PROFILE_REQUIRES_RECOVERY'=>'An existing disabled or delegated profile cannot be silently promoted. Trusted recovery is required.',
  'PROFILE_EVIDENCE_UNAVAILABLE'=>'Profile/audit history is missing or inconsistent. Do not bypass evidence checks.',default=>'Select a canonical DigiForge shop.'};}
}
