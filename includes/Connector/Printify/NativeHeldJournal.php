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
 /** Stage a reviewed connection's candidate in the local held journal. */
 public function prepareScoped(array $binding,string $candidateKey,?array $association=null):array|\WP_Error {
  if(!NativePortContract::valid('binding',$binding))return self::error('contract');
  if($association!==null){$association=self::normalizeAssociation($association);if($association===null)return self::error('association_contract');}
  $binding=array_map(static fn($value)=>$value,$binding);
  return $this->registerCandidate($binding,$candidateKey,false,static fn()=>self::currentScope($binding,$association),$association===null?null:NativePortContract::hash($association));
 }
 /** Persist one correlation for a local held intent. It cannot be submitted or fenced here. */
 public function prepareWitnessCorrelation(array $binding,string $candidateKey,string $intentDigest):array|\WP_Error {
  if(!NativePortContract::valid('binding',$binding)||$binding['environment']!=='test'||wp_get_environment_type()!=='local'
   ||!preg_match('/^[a-f0-9]{64}$/D',$intentDigest))return self::error('witness_correlation_contract');
  $binding=array_map(static fn($value)=>$value,$binding);
  $token=static fn()=>rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
  try{$requestId=$token();do{$challenge=$token();}while($challenge===$requestId);}
  catch(\Throwable){return self::error('witness_correlation_unavailable');}
  $correlation=['request_id'=>$requestId,'challenge'=>$challenge,'intent_digest'=>$intentDigest];
  return $this->registerCandidate($binding,$candidateKey,true,static fn()=>self::currentScope($binding),null,$correlation);
 }
 /** Consume one local fence only for the exact persisted binding and event head. */
 public function fenceScoped(array $binding,int $expected,string $head,?array $association=null):array|\WP_Error {
  if(!NativePortContract::valid('binding',$binding)||!preg_match('/^[a-f0-9]{64}$/D',$head))return self::error('contract');
  if($association!==null){$association=self::normalizeAssociation($association);if($association===null)return self::error('association_contract');}
  $binding=array_map(static fn($value)=>$value,$binding);
  return $this->advance($binding['operation_id'],$binding['shop_key'],$binding['actor_id'],$expected,'FENCE',$head,null,$binding,$association);
 }
 /** Run the reviewed admission check under the existing writer lock, then roll back without reserving a slot. */
 public function checkScopedAdmission(array $binding,string $candidateKey,array $association,int $sourceVersion=0):\WP_Error {
  $association=self::normalizeAssociation($association);
  if($association===null||!NativePortContract::valid('binding',$binding)||!preg_match('/^[a-f0-9]{64}$/D',$candidateKey)){
   return self::error('association_contract');
  }
  $result=$this->transaction($binding['operation_id'],self::candidateKey($binding,$candidateKey),
   static fn()=>NativeFailure::error('journal_and_independent_checkpoint_required'),$binding['shop_key'],$binding['actor_id'],
   static fn()=>self::currentScope($binding,$association,$sourceVersion));
  return is_wp_error($result)?$result:self::error('unexpected_admission_result');
 }
 /** Run a protected existing-product check with no operational success path. */
 public function checkExistingProductAdmission(array $binding,string $candidateKey,callable $product):\WP_Error {
  if(!NativePortContract::valid('binding',$binding)||!preg_match('/^[a-f0-9]{64}$/D',$candidateKey))return self::error('contract');
  $result=$this->transaction($binding['operation_id'],self::candidateKey($binding,$candidateKey),
   static fn()=>NativeFailure::error('journal_and_independent_checkpoint_required'),$binding['shop_key'],$binding['actor_id'],
   static function()use($binding,$product):true|\WP_Error {
    $scope=self::currentScope($binding);
    return is_wp_error($scope)?$scope:$product();
   });
  return is_wp_error($result)?$result:self::error('unexpected_admission_result');
 }
 private static function normalizeAssociation(array $association):?array {
  $pin=[];
  foreach(['mapping','spec','revision'] as $field){
   $value=$association[$field]??null;if(!is_int($value)||$value<1)return null;$pin[$field]=$value;
  }
  foreach(['plan','review','source'] as $field){
   $value=$association[$field]??null;if(!is_string($value)||!preg_match('/^[a-f0-9]{64}$/D',$value))return null;$pin[$field]=$value;
  }
  return count($association)===count($pin)?$pin:null;
 }
 private static function currentScope(array $binding,?array $association=null,int $sourceVersion=0):true|\WP_Error {
  $scope=NativeAuthorityAdapter::mutationScope($binding);
  if(is_wp_error($scope))return $scope;
  $owner=\DigiForge\PortalAccess\Owner::require($scope->shopKey);
  if(is_wp_error($owner)||$association===null)return $owner;
  if($scope->environment!=='test'||wp_get_environment_type()!=='local'
   ||$binding['product_version']!==0||$binding['expected_product_version']!==0)return self::error('association_scope');
  $locked=NativeBusinessMutationAssociation::lockForWriter($scope,$binding['actor_id'],$association['mapping'],
   $association['spec'],$association['plan'],$association['review'],$association['revision'],$binding['payload_fingerprint']);
  if(is_wp_error($locked))return $locked;
  if($sourceVersion!==0&&$locked['reviewed_source_version']!==$sourceVersion)return self::error('product_revision_changed');
  return $locked['source_hash']===$association['source'] && $locked['plan_policy_hash']===$binding['approval_reference']
   ? true : self::error('association_changed');
 }
 private function registerCandidate(array $binding,string $candidateKey,bool $exactReplay,?callable $current=null,?string $associationPin=null,?array $correlation=null):array|\WP_Error {
  try{$state=NativeHeldJournalState::prepare($binding,$candidateKey,time(),$current!==null,$associationPin,$correlation);}catch(\Throwable){return self::error('contract');}
  $access=$this->access($binding['shop_key'],$binding['actor_id']);if(is_wp_error($access))return $access;
  return $this->transaction($binding['operation_id'],self::candidateKey($binding,$candidateKey),function()use($state,$binding,$exactReplay){
   global $wpdb;[$j]=NativeHeldJournalSchema::tables();
   $wpdb->last_error='';$prior=$wpdb->get_results($wpdb->prepare("SELECT operation_id FROM $j WHERE (shop_key=%s AND operation_id=%s) OR candidate_key=%s FOR UPDATE",$binding['shop_key'],$binding['operation_id'],self::candidateKey($binding,$state['candidate_key'])),ARRAY_A);
   if(self::failed()||!is_array($prior))return self::error('read_unavailable');if($prior!==[]){
    if(!$exactReplay||count($prior)!==1||$prior[0]['operation_id']!==$binding['operation_id'])return self::error('consumed');
    $existing=$this->load($binding['operation_id'],$binding['shop_key'],$binding['actor_id'],true);
    if(is_wp_error($existing))return $existing;
    if($existing['record']['candidate_key']!==$state['candidate_key']||NativePortContract::hash($existing['record']['binding'])!==NativePortContract::hash($binding))return self::error('replay_substitution');
    if(($existing['record']['witness_correlation']['intent_digest']??null)!==($state['witness_correlation']['intent_digest']??null)){
     return self::error('witness_correlation_mismatch');
    }
    return $existing;
   }
   return $this->append($state,null);
  },$binding['shop_key'],$binding['actor_id'],$current);
 }
 public function transition(string $operationId,string $shop,int $actor,int $expected,string $action):array|\WP_Error {return $this->advance($operationId,$shop,$actor,$expected,$action,null,null);}
 /** Pin the exact current local generation and fence; not an independently enforceable dispatch generation. */
 public function transitionPinned(string $operationId,string $shop,int $actor,int $expected,string $head,?string $fence,string $action):array|\WP_Error {
  if(!preg_match('/^[a-f0-9]{64}$/D',$head)||($fence!==null&&!preg_match('/^[a-f0-9]{64}$/D',$fence)))return self::error('generation_contract');
  return $this->advance($operationId,$shop,$actor,$expected,$action,$head,$fence);
 }
 private function advance(string $operationId,string $shop,int $actor,int $expected,string $action,?string $head,?string $fence,?array $binding=null,?array $association=null):array|\WP_Error {
  if(!preg_match('/^[a-f0-9]{64}$/D',$operationId)||$expected<1||!in_array($action,['FENCE','CANCEL','UNKNOWN'],true))return self::error('transition_contract');
  $access=$this->access($shop,$actor);if(is_wp_error($access))return $access;
  $guard=$this->storage();if(is_wp_error($guard))return $guard;
  // Read a non-authoritative lock key; immutable row is reread/verified under transaction and row lock.
  global $wpdb;try{[$j]=NativeHeldJournalSchema::tables();$wpdb->last_error='';$key=$wpdb->get_var($wpdb->prepare("SELECT candidate_key FROM $j WHERE operation_id=%s AND shop_key=%s AND actor_id=%d",$operationId,$shop,$actor));}catch(\Throwable){return self::error('lookup_unavailable');}
  if(self::failed()||!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key))return self::error('missing_or_unavailable');
  $current=$binding===null?null:static fn()=>self::currentScope($binding,$association);
  $associationPin=$association===null?null:NativePortContract::hash($association);
  return $this->transaction($operationId,$key,function()use($operationId,$shop,$actor,$expected,$action,$head,$fence,$binding,$associationPin){
   $prior=$this->load($operationId,$shop,$actor,true);if(is_wp_error($prior))return $prior;
   if($action==='FENCE' && (($prior['record']['scope_recheck_required']??false)!==($binding!==null)))return self::error('scope_recheck_required');
   if($action==='FENCE' && ($prior['record']['association_pin']??null)!==$associationPin)return self::error('association_recheck_required');
   if($binding!==null&&!hash_equals(NativePortContract::hash($prior['record']['binding']),NativePortContract::hash($binding)))return self::error('binding_changed');
   if($head!==null&&($prior['event_hash']!==$head||$prior['record']['fence_hash']!==$fence))return self::error('stale_generation');
   try{$state=NativeHeldJournalState::advance($prior['record'],$action,$expected,time(),$action==='FENCE'?hash('sha256',random_bytes(32)):null);}catch(\Throwable){return self::error('stale_or_terminal');}
   return $this->append($state,$prior);
  },$shop,$actor,$current);
 }
 public function read(string $operationId,string $shop,int $actor):array|\WP_Error {
  if(!preg_match('/^[a-f0-9]{64}$/D',$operationId))return self::error('identity');$a=$this->access($shop,$actor);if(is_wp_error($a))return $a;
  $guard=$this->storage();if(is_wp_error($guard))return $guard;global $wpdb;
  $database=$wpdb;$started=false;
  try{
   if($database->query('START TRANSACTION READ ONLY')===false)return self::error('snapshot_unavailable');$started=true;
   $connection=NativeSourceTransaction::id();if(is_wp_error($connection))return self::error('snapshot_unknown');
   $r=$this->load($operationId,$shop,$actor,false);$a=$this->access($shop,$actor);if(is_wp_error($a))return $a;
   if(is_wp_error(NativeSourceTransaction::verify($database,$connection))||$database->query('COMMIT')===false)return self::error('snapshot_unknown');
   $started=false;return $r;
  }catch(\Throwable){return self::error('snapshot_unavailable');}
  finally{if($started){try{if($database->query('ROLLBACK')===false)return self::error('snapshot_unknown');}catch(\Throwable){return self::error('snapshot_unknown');}}}
 }
 private function storage():true|\WP_Error {
  try{
  global $wpdb;$wpdb->last_error='';$s=$wpdb->get_row('SELECT @@session.autocommit AS autocommit, @@session.in_transaction AS active, @@innodb_flush_log_at_trx_commit AS flush_commit, @@log_bin AS binlog, @@sync_binlog AS sync_binlog, @@tx_isolation AS isolation_level',ARRAY_A);
  if(self::failed()||!is_array($s)||(string)$s['autocommit']!=='1'||(string)$s['active']!=='0'||(string)$s['flush_commit']!=='1'||((string)$s['binlog']==='1'&&(string)$s['sync_binlog']!=='1')||strtoupper((string)$s['isolation_level'])!=='REPEATABLE-READ')return self::error('transaction_or_durability_unavailable');
  foreach(array_merge(NativeHeldJournalSchema::tables(),[Tables::audit_log(),$wpdb->options])as $table){$wpdb->last_error='';$engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));if(self::failed()||strtoupper((string)$engine)!=='INNODB')return self::error('schema_or_engine_unavailable');$wpdb->last_error='';$definition=$wpdb->get_row('SHOW CREATE TABLE `'.$table.'`',ARRAY_A);if(self::failed()||!is_array($definition)||!is_string($definition['Create Table']??null)||preg_match('/\bTEMPORARY\b/i',$definition['Create Table']))return self::error('temporary_or_unknown_table');}return true;
  }catch(\Throwable){return self::error('storage_unavailable');}
 }
 private function transaction(string $operationId,string $candidateKey,callable $work,string $shop,int $actor,?callable $current=null):array|\WP_Error {
  $guard=$this->storage();if(is_wp_error($guard))return $guard;global $wpdb;$database=$wpdb;$lock='dfnh:'.substr($candidateKey,0,58);$started=false;$committed=false;
  $wpdb->last_error='';try{if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1'||self::failed())return self::error('busy');}catch(\Throwable){return self::error('lock_unavailable');}
  $result=self::error('persistence');
  try{
   if($wpdb->query('START TRANSACTION')===false)return self::error('transaction');$started=true;
   $connection=NativeSourceTransaction::id();if(is_wp_error($connection))return self::error('transaction_changed');
   $a=$this->access($shop,$actor);if(is_wp_error($a))return $a;
   if(!$this->ownsWriter($database,$connection,$lock))return self::error('transaction_changed');
   if(!$this->pinWriterDependencies($database)||!$this->pinIdentityConstraints($database))return self::error('storage_constraints');
   if($current!==null){$scope=$current();if(is_wp_error($scope))return $scope;}
   if(!$this->ownsWriter($database,$connection,$lock))return self::error('transaction_changed');
   $result=$work();if(is_wp_error($result))return $result;
   $a=$this->access($shop,$actor);if(is_wp_error($a))return $a;
   if($current!==null){$scope=$current();if(is_wp_error($scope))return $scope;}
   if(!$this->ownsWriter($database,$connection,$lock)||$database->query('COMMIT')===false)return self::error('commit_unknown');$started=false;$committed=true;
  }catch(\Throwable){$result=self::error('persistence');}
  finally{$clean=true;try{if($started&&$database->query('ROLLBACK')===false)$clean=false;}catch(\Throwable){$clean=false;}try{$database->last_error='';$released=$database->get_var($database->prepare('SELECT RELEASE_LOCK(%s)',$lock));if($database->last_error!==''||(string)$released!=='1')$clean=false;}catch(\Throwable){$clean=false;}if(!$clean)return self::error('cleanup_unknown');}
  // Acknowledgement only after a fresh consistent, audit-anchored readback. Never restore a slot on failure.
  if(!$committed)return $result;$read=$this->read($operationId,$shop,$actor);if(is_wp_error($read)||$read['event_hash']!==$result['event_hash'])return self::error('confirmation_unknown');return $read;
 }
 /** Keep the audit and authority tables on transactional storage until commit. */
 private function pinWriterDependencies(object $database):bool {
  foreach([Tables::audit_log(),$database->options] as $table){
   $database->last_error='';
   // Opening the table retains a metadata lock on the storage used by this writer.
   if($database->query("SELECT 1 FROM `$table` LIMIT 0")===false||$database->last_error!=='')return false;
   $engine=$database->get_var($database->prepare(
    'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table
   ));
   if($database->last_error!==''||strtoupper((string)$engine)!=='INNODB')return false;
   $definition=$database->get_row("SHOW CREATE TABLE `$table`",ARRAY_A);
   if($database->last_error!==''||!is_string($definition['Create Table']??null)
    ||preg_match('/\bTEMPORARY\b/i',$definition['Create Table']))return false;
  }
  return true;
 }
 /** Check the unique identities while the transaction retains table metadata locks. */
 private function pinIdentityConstraints(object $database):bool {
  [$journal,$events]=NativeHeldJournalSchema::tables();
  $requirements=[
   $journal=>['PRIMARY'=>['shop_key','operation_id'],'candidate_key'=>['candidate_key']],
   $events=>['PRIMARY'=>['shop_key','operation_id','revision'],'event_hash'=>['event_hash']],
  ];
  foreach($requirements as $table=>$required){
   $database->last_error='';
   // Open the table without reading operation data. DDL must wait for this transaction.
   if($database->query("SELECT operation_id FROM `$table` LIMIT 0")===false||$database->last_error!=='')return false;
   $engine=$database->get_var($database->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));
   if($database->last_error!==''||strtoupper((string)$engine)!=='INNODB')return false;
   $rows=$database->get_results("SHOW INDEX FROM `$table`",ARRAY_A);
   if($database->last_error!==''||!is_array($rows))return false;
   $actual=[];
   foreach($rows as $row){
    $key=$row['Key_name']??null;
    if(!is_string($key)||!isset($required[$key]))continue;
    $position=(int)($row['Seq_in_index']??0);
    if((string)($row['Non_unique']??'')!=='0'||($row['Null']??null)!==''
       ||!array_key_exists('Sub_part',$row)||$row['Sub_part']!==null
       ||!isset($required[$key][$position-1])||isset($actual[$key][$position])
       ||($row['Column_name']??null)!==$required[$key][$position-1])return false;
    $actual[$key][$position]=$row['Column_name'];
   }
   foreach($required as $key=>$columns){
    if(count($actual[$key]??[])!==count($columns))return false;
   }
  }
  return true;
 }
 private function ownsWriter(object $database,string $connection,string $lock):bool {
  $database->last_error='';
  $owned=$database->get_var($database->prepare('SELECT IS_USED_LOCK(%s)',$lock));
  return $database->last_error===''&&(string)$owned===$connection
   && !is_wp_error(NativeSourceTransaction::verify($database,$connection));
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
