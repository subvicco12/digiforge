<?php
declare(strict_types=1);
namespace DigiForge\PortalAccess;
use DigiForge\Core\Capabilities;
use DigiForge\Security\{ShopAccessAuthority,ShopAccessPolicy,ShopAccessRepository};
/** Explicit global semantics layered over the unchanged Ed25519 trust verifier. */
final class GlobalOwnerAuthority {
 public const ROLE='Global Primary Owner';
 public const SCOPE='GLOBAL_PRIMARY_OWNER';
 /** Unsigned request material only; callers must never treat a draft as verified authority. */
 public static function draft(string $site,int $expiry,string $approval):array {
  $context=['owner_scope'=>self::SCOPE,'actor_id'=>1,'subject_email'=>Owner::EMAIL,'site'=>$site,'shops'=>ShopAccessPolicy::canonicalShops(),'caps'=>Capabilities::ALL,'expires_at'=>$expiry,'source_approval_hash'=>$approval];
  $set=hash('sha256',(string)json_encode($context,JSON_UNESCAPED_SLASHES));$records=[];
  foreach(ShopAccessPolicy::canonicalShops() as $shop)$records[]=['actor_id'=>1,'shop'=>$shop,'caps'=>Capabilities::ALL,'expires_at'=>$expiry,'source_approval_hash'=>$approval,'authority'=>'OWNER','owner_scope'=>self::SCOPE,'subject_email'=>Owner::EMAIL,'site'=>$site,'global_authority_id'=>$set];
  return $records;
 }
 public static function current():array|\WP_Error {
  $records=ShopAccessAuthority::records();return is_wp_error($records)?$records:self::resolve($records,home_url('/'),time());
 }
 /** Input is already signature-verified. This method supplies no signing or trust fallback. */
 public static function resolve(array $records,string $site,int $now):array|\WP_Error {
  $found=[];$first=null;
  foreach($records as $record){
   if(!ShopAccessPolicy::validRecord($record)||!in_array($record['authority']??null,['OWNER','DELEGATE'],true)||$record['expires_at']<=$now)return self::error('invalid');
   if($record['authority']!=='OWNER'){if(isset($record['owner_scope']))return self::error('conflict');continue;}
   if($record['actor_id']!==1||isset($found[$record['shop']]))return self::error('conflict');
   if(($record['owner_scope']??null)!==self::SCOPE)return self::error('required');
   if(($record['subject_email']??null)!==Owner::EMAIL||($record['site']??null)!==$site||$record['caps']!==Capabilities::ALL||!is_string($record['global_authority_id']??null))return self::error('scope');
   $first??=$record;
   foreach(['expires_at','source_approval_hash','global_authority_id'] as $field)if($record[$field]!==$first[$field])return self::error('conflict');
   $found[$record['shop']]=['record'=>$record,'hash'=>hash('sha256',(string)wp_json_encode($record))];
  }
  if(count($found)!==4||$first===null)return self::error('required');
  $expected=self::draft($site,$first['expires_at'],$first['source_approval_hash']);$ordered=[];
  foreach($expected as $record){$actual=$found[$record['shop']]??null;if($actual===null||$actual['record']!==$record)return self::error('scope');$ordered[$record['shop']]=$actual;}
  return ['records'=>$ordered,'hash'=>hash('sha256',(string)wp_json_encode(array_column($ordered,'record'))),'expires_at'=>$first['expires_at'],'source_approval_hash'=>$first['source_approval_hash'],'caps'=>Capabilities::ALL,'site'=>$site];
 }
 /** Checks live authority and all four exact audited native grants without recursing into ShopAccess. */
 public static function active(array $profile,bool $allowExpired=false):bool {
  if(($profile['role']??null)!==self::ROLE||($profile['actor_id']??null)!==1||($profile['enabled']??false)!==true||($profile['bootstrap_pending']??false)===true)return false;
  if(OwnerBootstrap::verifyClaim($profile)!==true)return false;
  $u=get_userdata(1);if(!$u||strtolower($u->user_email)!==Owner::EMAIL||!user_can(1,'manage_options'))return false;
  $authority=self::current();if(is_wp_error($authority)||($profile['global_authority_hash']??null)!==$authority['hash']||array_keys($profile['shops']??[])!==ShopAccessPolicy::canonicalShops())return false;
  foreach(Capabilities::ALL as $cap)if(!user_can(1,$cap))return false;
  $repo=new ShopAccessRepository();
  foreach($authority['records'] as $shop=>$a){
   if(($profile['shops'][$shop]??null)!==Capabilities::ALL||($profile['authority_hashes'][$shop]??null)!==$a['hash']||!is_string($profile['global_grants'][$shop]??null))return false;
   $state=$repo->read($shop);if(is_wp_error($state))return false;$id=$profile['global_grants'][$shop];$grant=$state['grants'][$id]??null;
   if(!is_array($grant)||$grant['actor_id']!==1||$grant['grantor_id']!==1||$grant['revoked']||(!$allowExpired&&$grant['expires_at']<=time())||$grant['expires_at']>$authority['expires_at']||$grant['caps']!==Capabilities::ALL||$grant['authority_hash']!==$a['hash']||$grant['source_approval_hash']!==$authority['source_approval_hash']||!in_array($grant['role'],$u->roles,true))return false;
   $role=get_role($grant['role']);if(!$role)return false;foreach(Capabilities::ALL as $cap)if(!$role->has_cap($cap))return false;
   if($repo->verifyGrantEvidence($id,$grant,$state['revision'])!==true)return false;
  }
  return true;
 }
 public static function error(string $code):\WP_Error{return new \WP_Error('digiforge_global_owner_'.$code,'A current independently signed Global Primary Owner authority for all four shops is required.',['status'=>403]);}
}
