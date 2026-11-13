<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
use DigiForge\Database\{Tables,ProviderConnectionSchema};
use DigiForge\Security\Logger;
/** Core-owned source observations. Observation revisions NEVER supply native business versions/epochs. */
final class NativeOrderSourceJournal {
 private const EVENT='native_order_source_registered';
 private const OBJECT='native_order_source';
 private static function error(string $why):\WP_Error{return NativeFailure::error('order_source_'.$why);}
 private static function scopeKey(ShopBinding $s):string{return NativePortContract::hash([$s->shopKey,$s->integrationId,$s->providerShopId,$s->environment]);}
 private static function encode(array $v):string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);}
 private function access(ShopBinding $s,int $actor):array|\WP_Error{return NativeAuthorityAdapter::scope($s,$actor,['manage_digiforge_orders']);}
 private function storage():true|\WP_Error {
  try{global $wpdb;$wpdb->last_error='';$v=$wpdb->get_row('SELECT @@session.autocommit AS ac, @@session.in_transaction AS active, @@innodb_flush_log_at_trx_commit AS flush_commit, @@log_bin AS binlog, @@sync_binlog AS sync_binlog, @@tx_isolation AS isolation_level',ARRAY_A);
   if($wpdb->last_error!==''||!is_array($v)||(string)$v['ac']!=='1'||(string)$v['active']!=='0'||(string)$v['flush_commit']!=='1'||((string)$v['binlog']==='1'&&(string)$v['sync_binlog']!=='1')||strtoupper((string)$v['isolation_level'])!=='REPEATABLE-READ')return self::error('durability_unavailable');
   foreach([NativeOrderSourceSchema::table(),Tables::audit_log(),$wpdb->options,Tables::integrations(),Tables::integration_secrets(),Tables::businesses(),Tables::stores(),Tables::product_programs(),ProviderConnectionSchema::table()]as $t){$wpdb->last_error='';$engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$t));if($wpdb->last_error!==''||strtoupper((string)$engine)!=='INNODB')return self::error('storage_unavailable');$definition=$wpdb->get_row('SHOW CREATE TABLE `'.$t.'`',ARRAY_A);if($wpdb->last_error!==''||!is_array($definition)||!is_string($definition['Create Table']??null)||preg_match('/\bTEMPORARY\b/i',$definition['Create Table']))return self::error('temporary_or_unknown_table');}
   return true;
  }catch(\Throwable){return self::error('storage_unavailable');}
 }
 /** Exact replay acknowledges only the current immutable source, never a renewed execution permit. */
 public function register(ShopBinding $s,int $actor,string $hash,int $expectedRevision,?string $expectedHead):array|\WP_Error {
  if($expectedRevision<0||$expectedRevision>256||($expectedRevision===0?$expectedHead!==null:!is_string($expectedHead)||preg_match('/^[a-f0-9]{64}$/D',$expectedHead)!==1))return self::error('generation_contract');
  $source=NativeOrderSourceEvidence::resolve($s,$actor,$hash);if(is_wp_error($source))return $source;$guard=$this->storage();if(is_wp_error($guard))return $guard;
  global $wpdb;$lock='dfnos:'.substr(NativePortContract::hash([self::scopeKey($s),$source['order_identity']]),0,57);$started=false;$acquired=false;$result=self::error('persistence_unknown');
  try{
   $wpdb->last_error='';$acquired=(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))==='1';if(!$acquired||$wpdb->last_error!=='')return self::error('reservation_busy');
   if($wpdb->query('START TRANSACTION')===false)return self::error('transaction_unavailable');$started=true;
   $fresh=NativeOrderSourceEvidence::resolve($s,$actor,$hash);if(is_wp_error($fresh)||$fresh!==$source)return self::error('authority_changed');
   $history=$this->history($s,$source['order_identity'],true);if(is_wp_error($history))return $history;$prior=$history===[]?null:end($history);$revision=count($history);$head=$prior['event_hash']??null;
   if($prior!==null&&$prior['record']['immutable_source_version']===$hash){
    $originalRevision=$revision-1;$originalHead=$originalRevision===0?null:$prior['record']['previous_hash'];
    if(!(($expectedRevision===$revision&&$expectedHead===$head)||($expectedRevision===$originalRevision&&$expectedHead===$originalHead))||!self::matches($prior['record'],$source))return self::error('replay_substitution');$result=$prior;
   }else{
    if($revision!==$expectedRevision||$head!==$expectedHead||$revision>=256)return self::error('stale_generation');
    foreach($history as $old)if($old['record']['immutable_source_version']===$hash)return self::error('stale_source');
    if($prior!==null&&($source['producer_audit_id']<=$prior['record']['producer_audit_id']||strtotime($source['observed_at'])<strtotime($prior['record']['observed_at'])))return self::error('out_of_order_source');
    $record=['schema'=>'DF_NATIVE_ORDER_OBSERVATION_1','scope_key'=>self::scopeKey($s),'order_identity'=>$source['order_identity'],'observation_revision'=>$revision+1,'previous_hash'=>$head??str_repeat('0',64),'immutable_source_version'=>$hash,'source_content_hash'=>$source['source_content_hash'],'approval_hash'=>$source['approval_hash'],'approval_revision'=>$source['approval_revision'],'producer_audit_id'=>$source['producer_audit_id'],'producer_actor_id'=>$source['producer_actor_id'],'recorded_by'=>$actor,'observed_at'=>$source['observed_at'],'native_business_version_verified'=>false,'independent_checkpoint_verified'=>false,'external_execution_authorized'=>false];
    $event=['record'=>$record];$event['seal']=hash_hmac('sha256',self::encode($event),wp_salt('auth'));$eventHash=NativePortContract::hash($event);$raw=self::encode($event);if(strlen($raw)>4096)return self::error('record_bound');
    $wpdb->last_error='';if($wpdb->insert(NativeOrderSourceSchema::table(),['scope_key'=>self::scopeKey($s),'order_identity'=>$source['order_identity'],'revision'=>$revision+1,'source_hash'=>$hash,'event_hash'=>$eventHash,'payload'=>$raw])!==1||$wpdb->last_error!==''||!Logger::write(self::EVENT,['scope_key'=>self::scopeKey($s),'order_identity'=>$source['order_identity'],'revision'=>$revision+1,'event_hash'=>$eventHash,'external_execution_authorized'=>false],self::OBJECT,$eventHash))return self::error('journal_or_audit');
    $result=['record'=>$record,'event_hash'=>$eventHash];
   }
   $last=NativeOrderSourceEvidence::resolve($s,$actor,$hash);if(is_wp_error($last)||$last!==$source)return self::error('authority_changed');
   if($wpdb->query('COMMIT')===false)return self::error('commit_unknown');$started=false;
  }catch(\Throwable){return self::error('persistence_unknown');}
  finally{
   $clean=true;try{if($started&&$wpdb->query('ROLLBACK')===false)$clean=false;}catch(\Throwable){$clean=false;}
   try{if($acquired){$wpdb->last_error='';$released=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));if($wpdb->last_error!==''||(string)$released!=='1')$clean=false;}}catch(\Throwable){$clean=false;}
   if(!$clean)return self::error('cleanup_unknown');
  }
  $read=$this->read($s,$actor,$source['order_identity']);return is_wp_error($read)||$read!==$result?self::error('confirmation_unknown'):$read;
 }
 public function read(ShopBinding $s,int $actor,string $identity):array|\WP_Error {
  if(preg_match('/^[a-f0-9]{64}$/D',$identity)!==1)return self::error('identity');$a=$this->access($s,$actor);if(is_wp_error($a))return $a;$g=$this->storage();if(is_wp_error($g))return $g;global $wpdb;$started=false;
  try{if($wpdb->query('START TRANSACTION READ ONLY')===false)return self::error('snapshot_unavailable');$started=true;$rows=$this->history($s,$identity,false);if(is_wp_error($rows)||$rows===[])return self::error('history_unavailable');$last=end($rows);$source=NativeOrderSourceEvidence::resolve($s,$actor,$last['record']['immutable_source_version']);if(is_wp_error($source)||!self::matches($last['record'],$source))return self::error('source_no_longer_current');if($wpdb->query('COMMIT')===false)return self::error('snapshot_unknown');$started=false;$fresh=NativeOrderSourceEvidence::resolve($s,$actor,$last['record']['immutable_source_version']);return is_wp_error($fresh)||!self::matches($last['record'],$fresh)?self::error('source_changed'):$last;
  }catch(\Throwable){return self::error('snapshot_unknown');}finally{if($started){try{if($wpdb->query('ROLLBACK')===false)return self::error('cleanup_unknown');}catch(\Throwable){return self::error('cleanup_unknown');}}}
 }
 /** Consistent inventory of registered core observations, not the provider's remote order inventory.
  * Missing schema/evidence is a denial; an actual empty installed table can yield an empty view.
  * This single read method does not qualify the aggregate WORKFLOW capability.
  */
 public function snapshot(ShopBinding $s,int $actor,int $limit):array|\WP_Error {
  if($limit<1||$limit>20)return self::error('view_limit');$a=$this->access($s,$actor);if(is_wp_error($a))return $a;$guard=$this->storage();if(is_wp_error($guard))return $guard;global $wpdb;$started=false;
  try{
   if($wpdb->query('START TRANSACTION READ ONLY')===false)return self::error('snapshot_unavailable');$started=true;$wpdb->last_error='';$table=NativeOrderSourceSchema::table();$ids=$wpdb->get_col($wpdb->prepare("SELECT order_identity FROM $table WHERE scope_key=%s GROUP BY order_identity ORDER BY order_identity LIMIT %d",self::scopeKey($s),$limit+1));if($wpdb->last_error!==''||!is_array($ids))return self::error('inventory_unavailable');
   // Compare both ordered inventory roots, including one look-ahead entry. A
   // journal-only rollback must not turn a retained audit-only order into [] or
   // silently pull another identity into the requested bounded window.
   $wpdb->last_error='';$auditIds=$wpdb->get_col($wpdb->prepare('SELECT JSON_UNQUOTE(JSON_EXTRACT(context,\'$.order_identity\')) AS identity FROM '.Tables::audit_log()." WHERE event_type=%s AND object_type=%s AND CASE WHEN JSON_VALID(context) THEN BINARY JSON_UNQUOTE(JSON_EXTRACT(context,'$.scope_key'))=BINARY %s ELSE false END GROUP BY identity ORDER BY identity LIMIT %d",self::EVENT,self::OBJECT,self::scopeKey($s),$limit+1));
   if($wpdb->last_error!==''||!is_array($auditIds)||$ids!==$auditIds)return self::error('inventory_root_mismatch');$items=[];$heads=[];
   foreach(array_slice($ids,0,$limit) as $id){$rows=$this->history($s,$id,false);if(is_wp_error($rows)||$rows===[])return self::error('inventory_integrity');$head=end($rows);$source=NativeOrderSourceEvidence::resolve($s,$actor,$head['record']['immutable_source_version']);if(is_wp_error($source)||!self::matches($head['record'],$source))return self::error('inventory_source');$heads[]=$head;$items[]=['scope_fingerprint'=>$s->fingerprint(),'kind'=>'ORDER_OBSERVATION','identity'=>$id,'disposition'=>'RECORDED','reason'=>'ORDER_REVIEW_REQUIRED','manual_review_required'=>true];}
   if($wpdb->query('COMMIT')===false)return self::error('snapshot_unknown');$started=false;$last=$this->access($s,$actor);if(is_wp_error($last)||$last['scope']!==$a['scope']||$last['session_fingerprint']!==$a['session_fingerprint'])return self::error('snapshot_authority_changed');
   // Approval/source authority is independent of connection/session authority;
   // repeat the protected source join outside the repeatable-read snapshot.
   foreach($heads as $head){$fresh=NativeOrderSourceEvidence::resolve($s,$actor,$head['record']['immutable_source_version']);if(is_wp_error($fresh)||!self::matches($head['record'],$fresh))return self::error('snapshot_source_changed');}
   $view=['schema'=>'DF_WORKFLOW_VIEW_1','scope_fingerprint'=>$s->fingerprint(),'actor_id'=>$actor,'items'=>$items,'read_only'=>true,'external_execution_authorized'=>false];$view['evidence_hash']=NativePortContract::hash($view);return NativePortContract::valid('workflow_view',$view)?$view:self::error('view_contract');
  }catch(\Throwable){return self::error('snapshot_unknown');}finally{if($started){try{if($wpdb->query('ROLLBACK')===false)return self::error('cleanup_unknown');}catch(\Throwable){return self::error('cleanup_unknown');}}}
 }
 private static function matches(array $record,array $source):bool{foreach(['order_identity','immutable_source_version','source_content_hash','approval_hash','approval_revision','producer_audit_id','producer_actor_id','observed_at']as $k)if(($record[$k]??null)!==($source[$k]??null))return false;return true;}
 /** Verify both source-event and audit roots; missing evidence is never an empty success. */
 private function history(ShopBinding $s,string $identity,bool $lock):array|\WP_Error {
  global $wpdb;$wpdb->last_error='';$table=NativeOrderSourceSchema::table();$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE scope_key=%s AND order_identity=%s ORDER BY revision LIMIT 257".($lock?' FOR UPDATE':''),self::scopeKey($s),$identity),ARRAY_A);if($wpdb->last_error!==''||!is_array($rows)||count($rows)>256)return self::error('history_unavailable');$out=[];$previous=str_repeat('0',64);$seen=[];
  foreach($rows as $i=>$row){
   if(!is_string($row['payload'])||strlen($row['payload'])>4096)return self::error('integrity');$event=json_decode($row['payload'],true);$seal=$event['seal']??null;
   if(!is_array($event)||array_keys($event)!==['record','seal']||!is_string($seal)||NativePortContract::hash($event)!==$row['event_hash'])return self::error('integrity');unset($event['seal']);if(!hash_equals(hash_hmac('sha256',self::encode($event),wp_salt('auth')),$seal))return self::error('seal');$r=$event['record'];
   if(($r['schema']??null)!=='DF_NATIVE_ORDER_OBSERVATION_1'||($r['scope_key']??null)!==self::scopeKey($s)||($r['order_identity']??null)!==$identity||($r['previous_hash']??null)!==$previous||($r['observation_revision']??null)!==$i+1||(int)$row['revision']!==$i+1||($r['immutable_source_version']??null)!==$row['source_hash']||isset($seen[$row['source_hash']])||($r['native_business_version_verified']??null)!==false||($r['independent_checkpoint_verified']??null)!==false||($r['external_execution_authorized']??null)!==false)return self::error('lineage');
   $wpdb->last_error='';$anchors=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s AND actor_id=%d LIMIT 2',self::EVENT,self::OBJECT,$row['event_hash'],$r['recorded_by']));$expected=['scope_key'=>self::scopeKey($s),'order_identity'=>$identity,'revision'=>$i+1,'event_hash'=>$row['event_hash'],'external_execution_authorized'=>false];
   if($wpdb->last_error!==''||!is_array($anchors)||count($anchors)!==1||NativePortContract::hash(json_decode($anchors[0],true,16,JSON_THROW_ON_ERROR))!==NativePortContract::hash($expected))return self::error('audit_integrity');$out[]=['record'=>$r,'event_hash'=>$row['event_hash']];$previous=$row['event_hash'];$seen[$row['source_hash']]=true;
  }
  $wpdb->last_error='';$anchors=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.Tables::audit_log()." WHERE event_type=%s AND object_type=%s AND CASE WHEN JSON_VALID(context) THEN BINARY JSON_UNQUOTE(JSON_EXTRACT(context,'$.scope_key'))=BINARY %s AND BINARY JSON_UNQUOTE(JSON_EXTRACT(context,'$.order_identity'))=BINARY %s ELSE false END LIMIT 257",self::EVENT,self::OBJECT,self::scopeKey($s),$identity));
  return $wpdb->last_error!==''||!is_array($anchors)||count($anchors)!==count($rows)?self::error('audit_stream_integrity'):$out;
 }
}
