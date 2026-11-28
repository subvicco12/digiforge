<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use DigiForge\Security\{Logger,ShopAccess};
use DigiForge\PortalAccess\Owner;
use WP_Error;

/** Authenticated local evidence only; never applies business or provider transitions. */
final class PrintifyWebhookInbox
{
 private const RECONCILIATION_HISTORY_LIMIT=1000;
 public static function authenticate(string $binding,string $raw,string $signature):array|WP_Error {
  try{$entry=PrintifyWebhookRequest::binding($binding);$secrets=[$entry['current_secret']];if(isset($entry['previous_secret'])&&$entry['previous_valid_until']>=time())$secrets[]=$entry['previous_secret'];$verified=null;
   foreach($secrets as $secret){try{$verified=PrintifyWebhookEvidence::verify($raw,$signature,$secret,(string)$entry['provider_shop_id'],time());break;}catch(\InvalidArgumentException){}}
   if($verified===null)return self::error('authentication',401);
   return $verified+['binding'=>$binding,'shop_key'=>$entry['shop_key'],'integration_id'=>$entry['integration_id']];
  }catch(\Throwable){return self::error('authentication',401);}
 }
 public static function receive(string $binding,string $raw,string $signature):array|WP_Error {
  $event=self::authenticate($binding,$raw,$signature);if($event instanceof WP_Error)return $event;
  return self::transaction($event['dedupe_key'],function()use($event){
   global $wpdb;$key='digiforge_pfy_hook_'. $event['dedupe_key'];$existing=self::read($key);if($existing instanceof WP_Error)return $existing;
   if($existing!==null){if(!self::anchored($existing,$event['dedupe_key']))return self::error('corrupt_receipt',503);if(($existing['binding']??null)!==$event['binding'])return self::error('binding_conflict',409);$disposition=PrintifyWebhookEvidence::replayDisposition($existing,$event);return $disposition==='CONFLICT_REJECT'?self::error('payload_conflict',409):['state'=>'DUPLICATE_NO_TRANSITION','business_transition_authorized'=>false];}
   $event['resource_mapping']=['provider'=>'printify','provider_resource_id'=>$event['resource_id'],'entity_type'=>explode(':',$event['topic'],2)[0],'shop_key'=>$event['shop_key'],'integration_id'=>$event['integration_id'],'requires_authoritative_readback'=>true];$event['state']='RECEIVED_LOCAL';$event['resource_type']=explode(':',$event['topic'],2)[0];$event['received_at']=gmdate('c');$event['evidence_mac']=hash_hmac('sha256',\DigiForge\Listings\Validator::canonicalJson($event),wp_salt('auth'));$event['receipt_sha256']=hash('sha256',(string)wp_json_encode($event));
   if($wpdb->insert($wpdb->options,['option_name'=>$key,'option_value'=>wp_json_encode($event),'autoload'=>'no'])!==1)return self::error('store',503);
   if(!Logger::write('printify_webhook_received',['receipt_sha256'=>$event['receipt_sha256'],'topic'=>$event['topic'],'integration_id'=>$event['integration_id']],'printify_webhook',$event['dedupe_key']))return self::error('audit',503);
   return ['state'=>'RECEIVED_LOCAL','receipt_sha256'=>$event['receipt_sha256'],'business_transition_authorized'=>false,'reconciliation_required'=>true];
  });
 }
 /** Explicit local retry/dead-letter evidence. No processing is scheduled. */
 public static function reconcile(string $dedupeKey,string $state,int $expectedRevision,string $note):array|WP_Error {
  if(!preg_match('/^[a-f0-9]{64}$/D',$dedupeKey)||!in_array($state,['RETRY_LOCAL','DEAD_LETTER','MANUALLY_RECONCILED'],true)||$expectedRevision<0||!preg_match('/^[a-zA-Z0-9 ._-]{1,160}$/D',$note))return self::error('manual_reconciliation',403);
  $finalAdmission=null;
  return self::transaction($dedupeKey,function()use($dedupeKey,$state,$expectedRevision,$note,&$finalAdmission){
   global $wpdb;$actor=get_current_user_id();$receipt=self::read('digiforge_pfy_hook_'.$dedupeKey);if($receipt instanceof WP_Error)return $receipt;if($receipt===null)return self::error('missing',404);if(!self::anchored($receipt,$dedupeKey))return self::error('corrupt_receipt',503);
   $shop=(string)($receipt['shop_key']??'');if(!self::reconciliationAccess($shop,$actor))return self::error('manual_reconciliation',403);
   $finalAdmission=static fn():bool=>self::reconciliationAccess($shop,$actor);
   $key='digiforge_pfy_hook_state_'.$dedupeKey;$old=self::read($key);if($old instanceof WP_Error)return $old;
   $history=self::reconciliationHistory($dedupeKey,$old);if($history instanceof WP_Error)return $history;
   $revision=$history['revision'];if($revision!==$expectedRevision)return self::error('revision',409);if($revision>=self::RECONCILIATION_HISTORY_LIMIT)return self::error('history_bound',503);
   $attempts=min(3,$history['attempts']+($state==='RETRY_LOCAL'?1:0));if($state==='RETRY_LOCAL'&&$attempts>=3)$state='DEAD_LETTER';
   $next=['state'=>$state,'revision'=>$revision+1,'attempts'=>$attempts,'note'=>$note,'updated_at'=>gmdate('c'),'scheduled'=>false,'business_transition_authorized'=>false];
   if(!self::reconciliationAccess($shop,$actor))return self::error('manual_reconciliation',403);
   $ok=$old===null?$wpdb->insert($wpdb->options,['option_name'=>$key,'option_value'=>wp_json_encode($next),'autoload'=>'no']):$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE BINARY option_name=BINARY %s AND BINARY option_value=BINARY %s",wp_json_encode($next),$key,wp_json_encode($old)));
   if($ok!==1||!Logger::write('printify_webhook_reconciled',$next,'printify_webhook',$dedupeKey))return self::error('reconciliation_store',503);
   $confirmed=self::read($key);if($confirmed instanceof WP_Error)return $confirmed;$verified=self::reconciliationHistory($dedupeKey,$confirmed);
   if($verified instanceof WP_Error)return $verified;if($confirmed!==$next)return self::error('corrupt_state',503);if(!self::reconciliationAccess($shop,$actor))return self::error('manual_reconciliation',403);return $next;
  },static function()use(&$finalAdmission):bool{return is_callable($finalAdmission)&&$finalAdmission();});
 }
 private static function reconciliationAccess(string $shop,int $actor):bool{return $actor>0&&get_current_user_id()===$actor&&current_user_can('manage_digiforge_connections')&&Owner::require($shop)===true&&ShopAccess::authorize($shop,'manage_digiforge_connections')===true&&get_current_user_id()===$actor&&is_user_logged_in()&&current_user_can('manage_digiforge_connections');}
 /** Local retained-history consistency only; coordinated rollback of state AND every audit root is not detectable here. */
 private static function reconciliationHistory(string $dedupeKey,?array $state):array|WP_Error {
  if($state!==null&&!self::validReconciliationState($state))return self::error('corrupt_state',503);
  global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT id,actor_id,context FROM '.Tables::audit_log().' WHERE BINARY event_type=BINARY %s AND BINARY object_type=BINARY %s AND BINARY object_id=BINARY %s ORDER BY id ASC LIMIT %d FOR UPDATE','printify_webhook_reconciled','printify_webhook',$dedupeKey,self::RECONCILIATION_HISTORY_LIMIT+1),ARRAY_A);
  if($wpdb->last_error!==''||!is_array($rows)||count($rows)>self::RECONCILIATION_HISTORY_LIMIT)return self::error('reconciliation_history_unavailable',503);
  $revision=0;$attempts=0;$latest=null;
  foreach($rows as $row){
   if(!preg_match('/^[1-9][0-9]*$/D',(string)($row['actor_id']??''))||!is_string($row['context']??null)||strlen($row['context'])>2048)return self::error('corrupt_state',503);
   try{$next=json_decode($row['context'],true,8,JSON_THROW_ON_ERROR);}catch(\Throwable){return self::error('corrupt_state',503);}
   if(!is_array($next)||!self::validReconciliationState($next)||$next['revision']!==$revision+1)return self::error('corrupt_state',503);
   $advanced=$next['attempts']===$attempts+1;
   if(($next['state']==='RETRY_LOCAL'&&(!$advanced||$next['attempts']>=3))||($next['state']==='MANUALLY_RECONCILED'&&$next['attempts']!==$attempts)||($next['state']==='DEAD_LETTER'&&$next['attempts']!==$attempts&&!($advanced&&$next['attempts']===3)))return self::error('corrupt_state',503);
   $revision=$next['revision'];$attempts=$next['attempts'];$latest=$next;
  }
  if(($state===null)!==($latest===null)||($state!==null&&\DigiForge\Listings\Validator::canonicalJson($state)!==\DigiForge\Listings\Validator::canonicalJson($latest)))return self::error('corrupt_state',503);
  return ['revision'=>$revision,'attempts'=>$attempts];
 }
 private static function validReconciliationState(array $state):bool {
  if(count($state)!==7||array_diff(array_keys($state),['state','revision','attempts','note','updated_at','scheduled','business_transition_authorized'])!==[]||!in_array($state['state']??null,['RETRY_LOCAL','DEAD_LETTER','MANUALLY_RECONCILED'],true)||!is_int($state['revision']??null)||$state['revision']<1||!is_int($state['attempts']??null)||$state['attempts']<0||$state['attempts']>3||!is_string($state['note']??null)||!preg_match('/^[a-zA-Z0-9 ._-]{1,160}$/D',$state['note'])||!is_string($state['updated_at']??null)||strlen($state['updated_at'])!==25||($state['scheduled']??null)!==false||($state['business_transition_authorized']??null)!==false)return false;
  $time=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',$state['updated_at']);return $time!==false&&$time->format('c')===$state['updated_at'];
 }
 private static function anchored(array $receipt,string $dedupeKey):bool {
  $sealed=$receipt;$seal=$sealed['receipt_sha256']??'';unset($sealed['receipt_sha256']);
  if(!is_string($seal)||!preg_match('/^[a-f0-9]{64}$/D',$seal)||($receipt['dedupe_key']??null)!==$dedupeKey||!hash_equals(hash('sha256',(string)wp_json_encode($sealed)),$seal))return false;
  $mac=$sealed['evidence_mac']??null;unset($sealed['evidence_mac']);if(!is_string($mac)||!hash_equals($mac,hash_hmac('sha256',\DigiForge\Listings\Validator::canonicalJson($sealed),wp_salt('auth'))))return false;
  global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s LIMIT 2','printify_webhook_received','printify_webhook',$dedupeKey));
  if($wpdb->last_error!==''||!is_array($rows)||count($rows)!==1)return false;
  foreach($rows as $raw){$anchor=json_decode($raw,true);if(is_array($anchor)&&isset($anchor['receipt_sha256'])&&is_string($anchor['receipt_sha256'])&&hash_equals($seal,$anchor['receipt_sha256']))return true;}return false;
 }
 private static function read(string $key):array|WP_Error|null {global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key));if($wpdb->last_error!=='')return self::error('read',503);if($raw===null)return null;$value=json_decode($raw,true);return is_array($value)?$value:self::error('corrupt',503);}
 private static function transaction(string $key,callable $callback,?callable $finalAdmission=null):array|WP_Error {
  global $wpdb;$database=$wpdb;$database->last_error='';$session=$database->get_row('SELECT CONNECTION_ID() AS connection_id, @@session.autocommit AS autocommit, @@session.in_transaction AS active',ARRAY_A);if($database->last_error!==''||!is_array($session)||preg_match('/^[1-9][0-9]*$/D',(string)($session['connection_id']??''))!==1||(string)($session['autocommit']??'')!=='1'||(string)($session['active']??'')!=='0')return self::error('transaction_active',503);
  $sessionId=(string)$session['connection_id'];
  foreach([$database->options,Tables::audit_log()] as $table){$database->last_error='';$engine=$database->get_var($database->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));if($database->last_error!==''||strtoupper((string)$engine)!=='INNODB')return self::error('durable_storage',503);}
  $driver=self::driver($database);if($driver===null)return self::error('transaction_driver',503);
  $lock='df_pfy_hook_'.substr($key,0,48);$acquired=self::acquireLock($database,$driver,$sessionId,$lock);if($acquired instanceof WP_Error)return $acquired;
  $started=false;$acknowledged=false;$fence=null;try{
   // Storage and named-lock reads can run hooks; never implicitly commit a new caller frame.
   $database->last_error='';$boundary=$database->get_row('SELECT CONNECTION_ID() AS connection_id, @@session.autocommit AS autocommit, @@session.in_transaction AS active',ARRAY_A);
   if($wpdb!==$database||$database->last_error!==''||!is_array($boundary)||(string)($boundary['connection_id']??'')!==$sessionId||(string)($boundary['autocommit']??'')!=='1'||(string)($boundary['active']??'')!=='0')return self::error('transaction_active',503);
   // Filters remain admission hooks, but cannot run between the last frame check
   // and the physical fixed control statement. No driver reconnect or retry.
   if(!self::controlAdmission($database,'START TRANSACTION'))return self::error('transaction',503);
   $idle=self::driverFrame($database,$driver,$sessionId);if($wpdb!==$database||$idle===null||(string)$idle['autocommit']!=='1'||(string)$idle['active']!=='0')return self::error('transaction_active',503);
   if(!self::controlSql($driver,'START TRANSACTION'))return self::error('transaction',503);$started=true;
   // Volatile SQL ownership only: this is not an independent recovery checkpoint.
   $fence='df_pfy_hook_owner_'.bin2hex(random_bytes(12));
   if(!self::controlSql($driver,'SAVEPOINT '.$fence)||!self::currentTransaction($database,$driver,$sessionId,$fence))return self::error('transaction_changed',503);
   $result=$callback();if(!self::currentTransaction($database,$driver,$sessionId,$fence))return self::error('transaction_changed',503);if($result instanceof WP_Error)return $result;
   if($finalAdmission!==null&&!$finalAdmission())return self::error('manual_reconciliation',403);if($wpdb!==$database)return self::error('transaction_changed',503);
   if(!self::controlAdmission($database,'COMMIT'))return self::error('commit_unknown',503);
   if($finalAdmission!==null&&!$finalAdmission())return self::error('manual_reconciliation',403);
   if(!self::currentTransaction($database,$driver,$sessionId,$fence)||!self::controlSql($driver,'COMMIT'))return self::error('commit_unknown',503);
   $confirmed=self::driverFrame($database,$driver,$sessionId);
   if($wpdb!==$database||$confirmed===null||(string)$confirmed['autocommit']!=='1'||(string)$confirmed['active']!=='0')return self::error('commit_unknown',503);
   $started=false;$acknowledged=true;return $result;
  }catch(\Throwable){return self::error('persistence',503);}finally{
   $cleanupFailed=false;
   // Cleanup alone may use the captured original connection after a global
   // wpdb swap. Its private fence must still exist; replacement frames survive.
   try{if($started&&$fence!==null&&apply_filters('query','ROLLBACK')==='ROLLBACK'&&self::ownedTransaction($driver,$sessionId,$fence)&&!self::controlSql($driver,'ROLLBACK'))$cleanupFailed=true;}catch(\Throwable){$cleanupFailed=true;}
   if(!self::releaseLock($driver,$sessionId,$lock))$cleanupFailed=true;
   // A durable decision remains committed; current admission still gates its private response.
   if($acknowledged&&$finalAdmission!==null){try{if(!$finalAdmission())return self::error('manual_reconciliation',403);if($wpdb!==$database||!self::sameDriver($database,$driver,$sessionId))return self::error('transaction_changed',503);}catch(\Throwable){return self::error('persistence',503);}}
   if($acknowledged&&$cleanupFailed)return self::error('persistence',503);
  }
 }
 private static function driver(\wpdb $database):?\mysqli {
  try{$driver=$database->dbh;return $driver instanceof \mysqli?$driver:null;}catch(\Throwable){return null;}
 }
 private static function sameDriver(\wpdb $database,\mysqli $driver,string $sessionId):bool {
  try{return self::driver($database)===$driver&&(string)\mysqli_thread_id($driver)===$sessionId;}catch(\Throwable){return false;}
 }
 private static function driverFrame(\wpdb $database,\mysqli $driver,string $sessionId):?array {
  if(!self::sameDriver($database,$driver,$sessionId))return null;
  return self::capturedFrame($driver,$sessionId);
 }
 private static function capturedFrame(\mysqli $driver,string $sessionId):?array {
  try{if((string)\mysqli_thread_id($driver)!==$sessionId)return null;}catch(\Throwable){return null;}
  try{$result=\mysqli_query($driver,'SELECT CONNECTION_ID() AS connection_id, @@session.autocommit AS autocommit, @@session.in_transaction AS active');if(!$result instanceof \mysqli_result)return null;try{$frame=\mysqli_fetch_assoc($result);}finally{\mysqli_free_result($result);}return is_array($frame)&&(string)($frame['connection_id']??'')===$sessionId?$frame:null;}catch(\Throwable){return null;}
 }
 private static function releaseLock(\mysqli $driver,string $sessionId,string $lock):bool {
  if(preg_match('/^df_pfy_hook_[a-f0-9]{48}$/D',$lock)!==1)return false;
  $sql="SELECT RELEASE_LOCK('".$lock."')";$admitted=false;
  try{$admitted=apply_filters('query',$sql)===$sql;}catch(\Throwable){}
  // Even a rejected cleanup hook cannot redirect release to a replacement link.
  // Release only the captured session's fixed lock; rejection still denies ACK.
  if(self::capturedFrame($driver,$sessionId)===null)return false;
  try{$result=\mysqli_query($driver,$sql);if(!$result instanceof \mysqli_result)return false;try{$row=\mysqli_fetch_row($result);}finally{\mysqli_free_result($result);}return $admitted&&is_array($row)&&(string)$row[0]==='1';}catch(\Throwable){return false;}
 }
 private static function acquireLock(\wpdb $database,\mysqli $driver,string $sessionId,string $lock):true|WP_Error {
  global $wpdb;if(preg_match('/^df_pfy_hook_[a-f0-9]{48}$/D',$lock)!==1)return self::error('busy',503);
  $sql="SELECT GET_LOCK('".$lock."', 0)";
  try{
   if(apply_filters('query',$sql)!==$sql)return self::error('busy',503);
   $idle=self::driverFrame($database,$driver,$sessionId);if($wpdb!==$database||$idle===null||(string)$idle['autocommit']!=='1'||(string)$idle['active']!=='0')return self::error('transaction_active',503);
   $before=self::lockOwner($driver,$sessionId,$lock);if($before===false||$before===$sessionId)return self::error('busy',503);
   $result=\mysqli_query($driver,$sql);$value=null;
   if($result instanceof \mysqli_result){try{$row=\mysqli_fetch_row($result);$value=is_array($row)?$row[0]:null;}finally{\mysqli_free_result($result);}}
   if((string)$value==='1')return true;
   // A single uncertain acquisition is never retried. Release only if this
   // previously unowned captured session is now observed owning the exact lock.
   if(self::lockOwner($driver,$sessionId,$lock)===$sessionId)self::releaseLock($driver,$sessionId,$lock);
   return self::error('busy',503);
  }catch(\Throwable){return self::error('busy',503);}
 }
 private static function lockOwner(\mysqli $driver,string $sessionId,string $lock):string|false|null {
  if(preg_match('/^df_pfy_hook_[a-f0-9]{48}$/D',$lock)!==1||self::capturedFrame($driver,$sessionId)===null)return false;
  try{$result=\mysqli_query($driver,"SELECT IS_USED_LOCK('".$lock."')");if(!$result instanceof \mysqli_result)return false;try{$row=\mysqli_fetch_row($result);}finally{\mysqli_free_result($result);}return is_array($row)?($row[0]===null?null:(string)$row[0]):false;}catch(\Throwable){return false;}
 }
 private static function controlAdmission(\wpdb $database,string $sql):bool {
  global $wpdb;return $wpdb===$database&&apply_filters('query',$sql)===$sql&&$wpdb===$database;
 }
 /** Closed grammar; never executes caller SQL, rewritten filter output or provider requests. */
 private static function controlSql(\mysqli $driver,string $sql):bool {
  if(!in_array($sql,['START TRANSACTION','COMMIT','ROLLBACK'],true)&&preg_match('/^(?:SAVEPOINT|RELEASE SAVEPOINT) df_pfy_hook_owner_[a-f0-9]{24}$/D',$sql)!==1)return false;
  try{return \mysqli_query($driver,$sql)===true;}catch(\Throwable){return false;}
 }
 private static function currentTransaction(\wpdb $database,\mysqli $driver,string $sessionId,string $fence):bool {
  global $wpdb;return $wpdb===$database&&self::sameDriver($database,$driver,$sessionId)&&self::ownedTransaction($driver,$sessionId,$fence)&&$wpdb===$database;
 }
 private static function ownedTransaction(\mysqli $driver,string $sessionId,string $fence):bool {
  $frame=self::capturedFrame($driver,$sessionId);
  // COMMIT/ROLLBACK erases the private marker even if the same connection starts
  // another transaction. Never roll back or acknowledge that replacement frame.
  return $frame!==null&&(string)$frame['autocommit']==='1'&&(string)$frame['active']==='1'&&self::controlSql($driver,'RELEASE SAVEPOINT '.$fence)&&self::controlSql($driver,'SAVEPOINT '.$fence);
 }
 private static function error(string $code,int $status):WP_Error{return new WP_Error('digiforge_printify_webhook_'.$code,'Webhook evidence unavailable.',['status'=>$status,'business_transition_authorized'=>false]);}
}
