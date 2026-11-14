<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
use DigiForge\Database\Tables;
use DigiForge\Security\Logger;
/** Core-owned candidate persistence, not connected to native exchange/dispatch. Schema is never auto-created. */
final class NativeHeldJournal {
 private const EVENT='native_printify_held_transition';
 private const OBJECT='native_printify_held';
 private function access(string $shop,int $actor):true|\WP_Error{return NativeAuthorityAdapter::module($shop,$actor,['manage_digiforge_pod']);}
 private static function error(string $why):\WP_Error{return NativeFailure::error('held_journal_'.$why);}
 private static function candidateKey(array $binding,string $key):string{return NativePortContract::hash(['DF_NATIVE_HELD_CANDIDATE_1',$binding['shop_key'],$binding['integration_id'],$binding['provider_shop_id'],$binding['environment'],$key]);}
 private static function encode(array $v):string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);}
 private static function failed():bool{global $wpdb;return $wpdb->last_error!=='';}
 /** Persist unverified, immutable candidate claims only. No approvals, epochs or physical identities are certified. */
 public function prepare(array $binding,string $candidateKey):array|\WP_Error {return $this->registerCandidate($binding,$candidateKey,false);}
 /** Idempotent candidate reconciliation only. Never reissues a fence, lease or operational acknowledgement. */
 public function register(array $binding,string $candidateKey):array|\WP_Error {return $this->registerCandidate($binding,$candidateKey,true);}
 private function registerCandidate(array $binding,string $candidateKey,bool $exactReplay):array|\WP_Error {
  try{$state=NativeHeldJournalState::prepare($binding,$candidateKey,time());}catch(\Throwable){return self::error('contract');}
  $access=$this->access($binding['shop_key'],$binding['actor_id']);if(is_wp_error($access))return $access;
  return $this->transaction($binding['operation_id'],self::candidateKey($binding,$candidateKey),function()use($state,$binding,$exactReplay){
   global $wpdb;[$j]=NativeHeldJournalSchema::tables();
   $wpdb->last_error='';$prior=$wpdb->get_results($wpdb->prepare("SELECT operation_id FROM $j WHERE (shop_key=%s AND operation_id=%s) OR candidate_key=%s FOR UPDATE",$binding['shop_key'],$binding['operation_id'],self::candidateKey($binding,$state['candidate_key'])),ARRAY_A);
   if(self::failed()||!is_array($prior))return self::error('read_unavailable');if($prior!==[]){
    if(!$exactReplay||count($prior)!==1||$prior[0]['operation_id']!==$binding['operation_id'])return self::error('consumed');
    $existing=$this->load($binding['operation_id'],$binding['shop_key'],$binding['actor_id'],true);
    if(is_wp_error($existing))return $existing;
    if($existing['record']['candidate_key']!==$state['candidate_key']||NativePortContract::hash($existing['record']['binding'])!==NativePortContract::hash($binding))return self::error('replay_substitution');
    return $existing;
   }
   return $this->append($state,null);
  },$binding['shop_key'],$binding['actor_id']);
 }
 public function transition(string $operationId,string $shop,int $actor,int $expected,string $action):array|\WP_Error {return $this->advance($operationId,$shop,$actor,$expected,$action,null,null);}
 /** Pin the exact current local generation and fence; not an independently enforceable dispatch generation. */
 public function transitionPinned(string $operationId,string $shop,int $actor,int $expected,string $head,?string $fence,string $action):array|\WP_Error {
  if(!preg_match('/^[a-f0-9]{64}$/D',$head)||($fence!==null&&!preg_match('/^[a-f0-9]{64}$/D',$fence)))return self::error('generation_contract');
  return $this->advance($operationId,$shop,$actor,$expected,$action,$head,$fence);
 }
 private function advance(string $operationId,string $shop,int $actor,int $expected,string $action,?string $head,?string $fence):array|\WP_Error {
  if(!preg_match('/^[a-f0-9]{64}$/D',$operationId)||$expected<1||!in_array($action,['FENCE','CANCEL','UNKNOWN'],true))return self::error('transition_contract');
  $access=$this->access($shop,$actor);if(is_wp_error($access))return $access;
  $guard=$this->storage();if(is_wp_error($guard))return $guard;
  // Read a non-authoritative lock key; immutable row is reread/verified under transaction and row lock.
  global $wpdb;try{[$j]=NativeHeldJournalSchema::tables();$wpdb->last_error='';$key=$wpdb->get_var($wpdb->prepare("SELECT candidate_key FROM $j WHERE operation_id=%s AND shop_key=%s AND actor_id=%d",$operationId,$shop,$actor));}catch(\Throwable){return self::error('lookup_unavailable');}
  if(self::failed()||!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key))return self::error('missing_or_unavailable');
  return $this->transaction($operationId,$key,function()use($operationId,$shop,$actor,$expected,$action,$head,$fence){
   $prior=$this->load($operationId,$shop,$actor,true);if(is_wp_error($prior))return $prior;
   if($head!==null&&($prior['event_hash']!==$head||$prior['record']['fence_hash']!==$fence))return self::error('stale_generation');
   try{$state=NativeHeldJournalState::advance($prior['record'],$action,$expected,time(),$action==='FENCE'?hash('sha256',random_bytes(32)):null);}catch(\Throwable){return self::error('stale_or_terminal');}
   return $this->append($state,$prior);
  },$shop,$actor);
 }
 public function read(string $operationId,string $shop,int $actor):array|\WP_Error {
  if(!preg_match('/^[a-f0-9]{64}$/D',$operationId))return self::error('identity');$a=$this->access($shop,$actor);if(is_wp_error($a))return $a;
  $guard=$this->storage();if(is_wp_error($guard))return $guard;global $wpdb;
  $started=false;
  try{if($wpdb->query('START TRANSACTION READ ONLY')===false)return self::error('snapshot_unavailable');$started=true;$r=$this->load($operationId,$shop,$actor,false);$a=$this->access($shop,$actor);if(is_wp_error($a))return $a;if($wpdb->query('COMMIT')===false)return self::error('snapshot_unknown');$started=false;return $r;}catch(\Throwable){return self::error('snapshot_unavailable');}finally{if($started){try{$wpdb->query('ROLLBACK');}catch(\Throwable){return self::error('snapshot_unknown');}}}
 }
 private function storage():true|\WP_Error {
  try{
  global $wpdb;$wpdb->last_error='';$s=$wpdb->get_row('SELECT @@session.autocommit AS autocommit, @@session.in_transaction AS active, @@innodb_flush_log_at_trx_commit AS flush_commit, @@log_bin AS binlog, @@sync_binlog AS sync_binlog, @@tx_isolation AS isolation_level',ARRAY_A);
  if(self::failed()||!is_array($s)||(string)$s['autocommit']!=='1'||(string)$s['active']!=='0'||(string)$s['flush_commit']!=='1'||((string)$s['binlog']==='1'&&(string)$s['sync_binlog']!=='1')||strtoupper((string)$s['isolation_level'])!=='REPEATABLE-READ')return self::error('transaction_or_durability_unavailable');
  foreach(array_merge(NativeHeldJournalSchema::tables(),[Tables::audit_log(),$wpdb->options])as $table){$wpdb->last_error='';$engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));if(self::failed()||strtoupper((string)$engine)!=='INNODB')return self::error('schema_or_engine_unavailable');$wpdb->last_error='';$definition=$wpdb->get_row('SHOW CREATE TABLE `'.$table.'`',ARRAY_A);if(self::failed()||!is_array($definition)||!is_string($definition['Create Table']??null)||preg_match('/\bTEMPORARY\b/i',$definition['Create Table']))return self::error('temporary_or_unknown_table');}return true;
  }catch(\Throwable){return self::error('storage_unavailable');}
 }
 private function transaction(string $operationId,string $candidateKey,callable $work,string $shop,int $actor):array|\WP_Error {
  $guard=$this->storage();if(is_wp_error($guard))return $guard;global $wpdb;$lock='dfnh:'.substr($candidateKey,0,58);$started=false;$committed=false;
  $wpdb->last_error='';try{if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1'||self::failed())return self::error('busy');}catch(\Throwable){return self::error('lock_unavailable');}
  $result=self::error('persistence');
  try{
   if($wpdb->query('START TRANSACTION')===false)return self::error('transaction');$started=true;
   $a=$this->access($shop,$actor);if(is_wp_error($a))return $a;$result=$work();if(is_wp_error($result))return $result;
   $a=$this->access($shop,$actor);if(is_wp_error($a))return $a;
   if($wpdb->query('COMMIT')===false)return self::error('commit_unknown');$started=false;$committed=true;
  }catch(\Throwable){$result=self::error('persistence');}
  finally{$clean=true;try{if($started&&$wpdb->query('ROLLBACK')===false)$clean=false;}catch(\Throwable){$clean=false;}try{$wpdb->last_error='';$released=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));if(self::failed()||(string)$released!=='1')$clean=false;}catch(\Throwable){$clean=false;}if(!$clean)return self::error('cleanup_unknown');}
  // Acknowledgement only after a fresh consistent, audit-anchored readback. Never restore a slot on failure.
  if(!$committed)return $result;$read=$this->read($operationId,$shop,$actor);if(is_wp_error($read)||$read['event_hash']!==$result['event_hash'])return self::error('confirmation_unknown');return $read;
 }
 private function append(array $record,?array $prior):array|\WP_Error {
  global $wpdb;[$j,$e]=NativeHeldJournalSchema::tables();$op=$record['binding']['operation_id'];$event=['schema'=>'DF_NATIVE_HELD_EVENT_1','previous_hash'=>$prior['event_hash']??str_repeat('0',64),'record'=>$record];
  $event['seal']=hash_hmac('sha256',self::encode($event),wp_salt('auth'));$hash=NativePortContract::hash($event);$raw=self::encode($event);if(strlen($raw)>16384)return self::error('event_bound');
  $wpdb->last_error='';if($wpdb->insert($e,['shop_key'=>$record['binding']['shop_key'],'operation_id'=>$op,'revision'=>$record['revision'],'event_hash'=>$hash,'payload'=>$raw])!==1)return self::error('event_store');
  $row=['revision'=>$record['revision'],'head_hash'=>$hash,'snapshot'=>self::encode($record)];
  $ok=$prior===null?$wpdb->insert($j,$row+['operation_id'=>$op,'candidate_key'=>self::candidateKey($record['binding'],$record['candidate_key']),'shop_key'=>$record['binding']['shop_key'],'actor_id'=>$record['binding']['actor_id']]):$wpdb->update($j,$row,['operation_id'=>$op,'shop_key'=>$record['binding']['shop_key'],'revision'=>$prior['record']['revision'],'head_hash'=>$prior['event_hash']]);
  if($ok!==1||!Logger::write(self::EVENT,['operation_id'=>$op,'shop_key'=>$record['binding']['shop_key'],'event_hash'=>$hash,'revision'=>$record['revision'],'dispatch_permitted'=>false],self::OBJECT,$hash))return self::error('journal_or_audit_store');
  return ['record'=>$record,'event_hash'=>$hash];
 }
 private function load(string $op,string $shop,int $actor,bool $lock):array|\WP_Error {
  global $wpdb;[$j,$e]=NativeHeldJournalSchema::tables();$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $j WHERE operation_id=%s AND shop_key=%s AND actor_id=%d".($lock?' FOR UPDATE':''),$op,$shop,$actor),ARRAY_A);
  if(self::failed()||!is_array($row))return self::error('missing_or_unavailable');
  $wpdb->last_error='';$events=$wpdb->get_results($wpdb->prepare("SELECT * FROM $e WHERE operation_id=%s AND shop_key=%s ORDER BY revision ASC LIMIT 257",$op,$shop),ARRAY_A);
  if(self::failed()||!is_array($events)||count($events)<1||count($events)>256)return self::error('history_unavailable');$prior=null;
  foreach($events as $index=>$entry){
   if(!is_string($entry['payload'])||strlen($entry['payload'])>16384)return self::error('history_integrity');$v=json_decode($entry['payload'],true);$seal=$v['seal']??null;
   if(!is_array($v)||array_keys($v)!==['schema','previous_hash','record','seal']||$v['schema']!=='DF_NATIVE_HELD_EVENT_1'||!is_string($seal)||!hash_equals($entry['event_hash'],NativePortContract::hash($v)))return self::error('history_integrity');
   unset($v['seal']);if(!hash_equals(hash_hmac('sha256',self::encode($v),wp_salt('auth')),$seal)||!NativeHeldJournalState::valid($v['record'])||$v['previous_hash']!==($prior['event_hash']??str_repeat('0',64))||(int)$entry['revision']!==$index+1||$v['record']['revision']!==$index+1)return self::error('history_integrity');
   $state=$v['record'];if($state['binding']['operation_id']!==$op||$state['binding']['shop_key']!==$shop||$state['binding']['actor_id']!==$actor||self::candidateKey($state['binding'],$state['candidate_key'])!==$row['candidate_key'])return self::error('history_scope');
   if($prior!==null){try{$action=match($state['state']){'FENCED'=>'FENCE','CANCELLED'=>'CANCEL','UNKNOWN'=>'UNKNOWN',default=>throw new \InvalidArgumentException()};$next=NativeHeldJournalState::advance($prior['record'],$action,$index,$state['occurred_at'],$action==='FENCE'?$state['fence_hash']:null);if($next!==$state)return self::error('history_transition');}catch(\Throwable){return self::error('history_transition');}}
   $wpdb->last_error='';$anchors=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s AND actor_id=%d LIMIT 2',self::EVENT,self::OBJECT,$entry['event_hash'],$actor));
   if(self::failed()||!is_array($anchors)||count($anchors)!==1)return self::error('audit_integrity');$a=json_decode($anchors[0],true);if(!is_array($a)||($a['shop_key']??null)!==$shop||($a['operation_id']??null)!==$op||($a['event_hash']??null)!==$entry['event_hash']||($a['revision']??null)!==$index+1||($a['dispatch_permitted']??null)!==false)return self::error('audit_integrity');
   $prior=['record'=>$state,'event_hash'=>$entry['event_hash']];
  }
  // Enumerate both roots: retained audit anchors must not disappear behind a rolled-back journal head.
  $wpdb->last_error='';$stream=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.Tables::audit_log()." WHERE event_type=%s AND object_type=%s AND actor_id=%d AND CASE WHEN JSON_VALID(context) THEN BINARY JSON_UNQUOTE(JSON_EXTRACT(context,'$.operation_id'))=BINARY %s AND BINARY JSON_UNQUOTE(JSON_EXTRACT(context,'$.shop_key'))=BINARY %s ELSE false END LIMIT 257",self::EVENT,self::OBJECT,$actor,$op,$shop));
  if(self::failed()||!is_array($stream)||count($stream)!==count($events))return self::error('audit_stream_integrity');
  if($prior===null||(int)$row['revision']!==count($events)||$row['head_hash']!==$prior['event_hash']||$row['snapshot']!==self::encode($prior['record']))return self::error('head_integrity');return $prior;
 }
}
