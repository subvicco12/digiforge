<?php
declare(strict_types=1);
namespace DigiForge\PortalAccess;
use DigiForge\Security\Logger;
/** Existing WP accounts only; no email dispatch, user creation, roles or capability mutation. */
final class Invitations {
 public static function create(int $subject,string $role,string $shop,array $modules,int $expiry,string $request):array|\WP_Error {
  if(Owner::require($shop)!==true||$expiry<=time()||$expiry>time()+86400||!get_userdata($subject)||$subject===get_current_user_id()||!preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$request))return Policy::error('invitation');$caps=Policy::permissions($role,$modules);if(is_wp_error($caps))return $caps;$old=Store::read($subject);if(is_wp_error($old)||$old!==null)return Policy::error('existing_profile');
  $r=Store::change($subject,$role,[$shop=>$modules],false,0,$request);if(is_wp_error($r))return $r;$r=Store::grantNative($subject,[$shop=>$caps]);if(is_wp_error($r))return $r;
  $token=bin2hex(random_bytes(32));$id=hash('sha256',$token);$invite=['subject_id'=>$subject,'owner_id'=>get_current_user_id(),'shop'=>$shop,'caps'=>$caps,'role'=>$role,'expires_at'=>$expiry,'profile_revision'=>1,'consumed'=>false];global $wpdb;
  if($wpdb->query('START TRANSACTION')===false)return Policy::error('transaction',503);$key='digiforge_portal_invite_'.$id;
  if($wpdb->insert($wpdb->options,['option_name'=>$key,'option_value'=>wp_json_encode($invite),'autoload'=>'no'])!==1||!Logger::write('portal_invitation_created',['invitation_id'=>$id,'invitation'=>$invite],'portal_invitation',$id)||$wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return Policy::error('audit',503);}return ['token'=>$token,'expires_at'=>$expiry,'subject_id'=>$subject,'delivery'=>'PRIVATE_MANUAL_HANDOFF_ONLY'];
 }
 public static function accept(string $token,string $request):array|\WP_Error {
  if(!preg_match('/^[a-f0-9]{64}$/D',$token))return Policy::error('invitation');$id=hash('sha256',$token);$key='digiforge_portal_invite_'.$id;global $wpdb;$lock='df_inv_'.substr($id,0,48);
  if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1')return Policy::error('busy',409);
  try{$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key));if($wpdb->last_error!==''||!is_string($raw)||strlen($raw)>8192)return Policy::error('invitation');$i=json_decode($raw,true);
   if(!is_array($i)||($i['subject_id']??null)!==get_current_user_id()||($i['consumed']??null)!==false||!is_int($i['expires_at']??null)||$i['expires_at']<=time()||!is_string($i['shop']??null)||!is_int($i['owner_id']??null))return Policy::error('invitation');
   $audit=$wpdb->get_var($wpdb->prepare("SELECT context FROM ".\DigiForge\Database\Tables::audit_log()." WHERE object_type='portal_invitation' AND object_id=%s AND event_type='portal_invitation_created' ORDER BY id ASC LIMIT 1",$id));$e=is_string($audit)?json_decode($audit,true):null;
   if($wpdb->last_error!==''||($e['invitation_id']??null)!==$id||($e['invitation']??null)!==$i||Owner::forActor($i['owner_id'],$i['shop'])!==true)return Policy::error('invitation_authority');
   $s=Store::read(get_current_user_id());if(is_wp_error($s)||$s===null||$s['enabled']||$s['revision']!==$i['profile_revision']||$s['role']!==$i['role']||($s['shops'][$i['shop']]??null)!==$i['caps'])return Policy::error('invitation_state');
   // Native assignment must still be complete, signed, current, scoped and unrevoked.
   $repo=new \DigiForge\Security\ShopAccessRepository();$native=$repo->read($i['shop']);$found=false;if(is_wp_error($native))return $native;
   foreach($native['grants'] as $grantId=>$g){if($g['actor_id']!==get_current_user_id()||$g['grantor_id']!==$i['owner_id']||$g['caps']!==$i['caps']||$g['revoked']||$g['expires_at']<=time())continue;$role=get_role($g['role']);$user=get_userdata(get_current_user_id());if(!$role||!in_array($g['role'],$user->roles,true))continue;$ok=true;foreach($g['caps'] as $cap)if(!user_can($user,$cap)||!$role->has_cap($cap))$ok=false;$a=\DigiForge\Security\ShopAccessRepository::authorityFor($g['grantor_id'],$i['shop'],$g['caps'],$g['expires_at'],null,$g['authority_hash']);if($ok&&!is_wp_error($a)&&$repo->verifyGrantEvidence($grantId,$g,$native['revision'])===true){$found=true;break;}}
   if(!$found)return Policy::error('invitation_authority');$s['enabled']=true;$s['revision']++;$s['generation']++;
   $result=Store::save($s,$i['profile_revision'],$request,static function()use($wpdb,$key,$i,$id){$done=$i;$done['consumed']=true;return $wpdb->update($wpdb->options,['option_value'=>wp_json_encode($done)],['option_name'=>$key,'option_value'=>wp_json_encode($i)])===1&&Logger::write('portal_invitation_accepted',['invitation_id'=>$id,'subject_id'=>$i['subject_id']],'portal_invitation',$id);});if(!is_wp_error($result))Session::start();return $result;
  }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
 }
}
