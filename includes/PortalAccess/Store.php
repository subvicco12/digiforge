<?php
declare(strict_types=1);
namespace DigiForge\PortalAccess;
use DigiForge\Security\{Logger,ShopAccessPolicy,ShopAccessRepository};
/** Audited application-only profiles. Never changes wp_users, usermeta or WordPress roles. */
final class Store {
 public static function key(int $actor):string{return 'digiforge_portal_profile_'.$actor;}
 public static function read(int $actor):array|\WP_Error|null {
  global $wpdb;if($actor<1)return Policy::error('actor');$wpdb->last_error='';
  $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",self::key($actor)));
  if($wpdb->last_error!=='')return Policy::error('profile_read',503);
  $events=$wpdb->get_col($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1001",$wpdb->esc_like('digiforge_portal_event_'.$actor.'_').'%'));
  if($wpdb->last_error!=='')return Policy::error('profile_read',503);
  $audit=$wpdb->get_col($wpdb->prepare("SELECT context FROM ".\DigiForge\Database\Tables::audit_log()." WHERE object_type='portal_access_profile' AND object_id=%s AND event_type='portal_profile_changed' ORDER BY id ASC LIMIT 1001",(string)$actor));
  if($wpdb->last_error!==''||!is_array($events)||!is_array($audit))return Policy::error('profile_read',503);
  if($raw===null)return $events===[]&&$audit===[]?null:Policy::error('profile_evidence',503);
  if(!is_string($raw)||strlen($raw)>16384)return Policy::error('profile_corrupt',503);$state=json_decode($raw,true);
  if(!self::shape($state,$actor)||count($events)!==$state['revision']||count($audit)!==$state['revision']||count($events)>1000)return Policy::error('profile_evidence',503);
  $decoded=[];foreach($events as $event){$e=json_decode($event,true);if(!is_array($e)||!is_int($e['revision']??null))return Policy::error('profile_evidence',503);$decoded[]=$e;}usort($decoded,static fn($a,$b)=>$a['revision']<=>$b['revision']);$prior='';
  foreach($decoded as $i=>$e){$a=json_decode($audit[$i],true);if($a!==$e||$e['revision']!==$i+1||($e['target_id']??null)!==$actor||($e['prior_hash']??null)!==$prior||!self::shape($e['state']??null,$actor)||$e['state']['revision']!==$i+1||($e['state_hash']??null)!==self::hash($e['state']))return Policy::error('profile_evidence',503);$prior=$e['state_hash'];}
  return $decoded[count($decoded)-1]['state']===$state?$state:Policy::error('profile_evidence',503);
 }
 private static function shape(mixed $s,int $actor):bool {
  if(!is_array($s)||($s['actor_id']??null)!==$actor||!in_array($s['role']??null,['Primary Owner','Admin','Operator','Viewer'],true)||!is_bool($s['enabled']??null)||!is_int($s['revision']??null)||$s['revision']<1||!is_int($s['generation']??null)||$s['generation']<1||!is_array($s['shops']??null)||count($s['shops'])>4)return false;
  foreach($s['shops'] as $shop=>$caps){if(!is_string($shop)||!ShopAccessPolicy::validShop($shop)||!is_array($caps)||!array_is_list($caps)||!$caps||count($caps)>32||count(array_unique($caps,SORT_REGULAR))!==count($caps))return false;foreach($caps as $c)if(!is_string($c)||!in_array($c,\DigiForge\Core\Capabilities::ALL,true))return false;}return true;
 }
 public static function hash(array $s):string{return hash('sha256',(string)wp_json_encode($s));}
 /** @internal Persist only after domain authorization; one transaction binds snapshot/event/audit. */
 public static function save(array $next,int $revision,string $request,?callable $extra=null):array|\WP_Error {
  global $wpdb;if(!preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$request)||!self::shape($next,$next['actor_id']??0))return Policy::error('typed');
  foreach([$wpdb->options,\DigiForge\Database\Tables::audit_log()] as $t){$wpdb->last_error='';$engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$t));if($wpdb->last_error!==''||strtoupper((string)$engine)!=='INNODB')return Policy::error('storage',503);}
  if($wpdb->query('START TRANSACTION')===false)return Policy::error('transaction',503);
  $fail=static function($e)use($wpdb){$wpdb->query('ROLLBACK');return $e;};$actor=$next['actor_id'];
  // Account-scoped advisory lock also serializes the first absent snapshot.
  $lock='df_portal_'.substr(hash('sha256',$wpdb->options.':'.$actor),0,48);if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1')return $fail(Policy::error('busy',409));
  try{$old=self::read($actor);if(is_wp_error($old))return $fail($old);if(($old['revision']??0)!==$revision||$next['revision']!==$revision+1||$next['generation']!==($old['generation']??0)+1)return $fail(Policy::error('revision',409));
   $key='digiforge_portal_event_'.$actor.'_'.$next['revision'];$e=['target_id'=>$actor,'actor_id'=>get_current_user_id(),'revision'=>$next['revision'],'request_id'=>$request,'prior_hash'=>$old===null?'':self::hash($old),'state_hash'=>self::hash($next),'state'=>$next];
   // A request ID is single-use for this actor, even after another revision is added.
   $seen=$wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name=%s",'digiforge_portal_request_'.hash('sha256',$actor.':'.$request)));
   if($seen!==null||$wpdb->last_error!=='')return $fail(Policy::error('replay',409));
   if($wpdb->insert($wpdb->options,['option_name'=>$key,'option_value'=>wp_json_encode($e),'autoload'=>'no'])!==1||$wpdb->insert($wpdb->options,['option_name'=>'digiforge_portal_request_'.hash('sha256',$actor.':'.$request),'option_value'=>(string)$next['revision'],'autoload'=>'no'])!==1||!Logger::write('portal_profile_changed',$e,'portal_access_profile',(string)$actor))return $fail(Policy::error('audit',503));
   $ok=$old===null?$wpdb->insert($wpdb->options,['option_name'=>self::key($actor),'option_value'=>wp_json_encode($next),'autoload'=>'no']):$wpdb->update($wpdb->options,['option_value'=>wp_json_encode($next)],['option_name'=>self::key($actor)]);
   if($ok!==1||($extra!==null&&$extra()!==true)||$wpdb->query('COMMIT')===false)return $fail(Policy::error('write',503));return $next;
  }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
 }
 public static function change(int $actor,string $role,array $shops,bool $enabled,int $revision,string $request):array|\WP_Error {
  $old=self::read($actor);if(is_wp_error($old))return $old;if(!in_array($role,['Admin','Operator','Viewer'],true)||$actor<1||!get_userdata($actor)||($old['role']??null)==='Primary Owner'||($old['revision']??0)!==$revision||count($shops)>4)return Policy::error('target');$scopes=[];
  foreach(array_unique(array_merge(array_keys($shops),array_keys($old['shops']??[]))) as $shop){if(!is_string($shop)||Owner::require($shop)!==true)return Policy::error('owner');}
  foreach($shops as $shop=>$modules){if($modules===[]&&$old!==null)continue;if(!is_array($modules))return Policy::error('typed');$caps=Policy::permissions($role,$modules);if(is_wp_error($caps))return $caps;foreach($caps as $cap)if(!user_can($actor,$cap))return Policy::error('ordinary_capability');$scopes[$shop]=$caps;}
  if($enabled&&$scopes===[])return Policy::error('empty_scope');
  // Commit a restrictive snapshot before creating any native grant. Interrupted work
  // leaves the actor disabled, never an unmanaged legacy actor with a new grant.
  $next=['actor_id'=>$actor,'role'=>$role,'enabled'=>false,'revision'=>$revision+1,'generation'=>($old['generation']??0)+1,'shops'=>$scopes,'managed_shops'=>array_values(array_unique(array_merge($old['managed_shops']??[],array_keys($old['shops']??[]),array_keys($shops))))];
  $saved=self::save($next,$revision,$request);if(is_wp_error($saved)||!$enabled)return $saved;
  $r=self::grantNative($actor,$scopes);if(is_wp_error($r))return $r;
  $next['enabled']=true;$next['revision']++;$next['generation']++;
  return self::save($next,$revision+1,hash('sha256',$request.':activate'));
 }
 /** Creates native scoped evidence only in the already authenticated signed owner's context. */
 public static function grantNative(int $actor,array $scopes):true|\WP_Error {
  $user=get_userdata($actor);if(!$user)return Policy::error('target');foreach($scopes as $shop=>$caps){if(Owner::require($shop)!==true)return Policy::error('owner');foreach($caps as $cap)if(!self::permits(get_current_user_id(),$shop,$cap))return Policy::error('owner_scope');$role=null;foreach($user->roles as $r){$wpRole=get_role($r);if($wpRole&&array_diff($caps,array_keys(array_filter($wpRole->capabilities)))===[]){$role=$r;break;}}if($role===null)return Policy::error('ordinary_role');$a=\DigiForge\Security\ShopAccessRepository::authorityFor(get_current_user_id(),$shop,$caps,time()+60);if(is_wp_error($a))return $a;$expiry=min(time()+3600,$a['record']['expires_at']);$repo=new ShopAccessRepository();$state=$repo->read($shop);if(is_wp_error($state))return $state;$r=$repo->grant($actor,$shop,$role,$caps,$expiry,wp_generate_uuid4(),$state['revision']);if(is_wp_error($r))return $r;}return true;
 }
 public static function permits(int $actor,string $shop,string $cap):bool {
  $s=self::read($actor);if($s===null)return true;if(is_wp_error($s)||!$s['enabled']||!in_array($cap,$s['shops'][$shop]??[],true))return false;return $actor!==get_current_user_id()||Session::valid($s);
 }
}
