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
  $lock='df_pfy_hook_'.substr($key,0,48);if((string)$database->get_var($database->prepare('SELECT GET_LOCK(%s, 0)',$lock))!=='1')return self::error('busy',503);
  $started=false;$acknowledged=false;try{
   // Storage and named-lock reads can run hooks; never implicitly commit a new caller frame.
   $database->last_error='';$boundary=$database->get_row('SELECT CONNECTION_ID() AS connection_id, @@session.autocommit AS autocommit, @@session.in_transaction AS active',ARRAY_A);
   if($wpdb!==$database||$database->last_error!==''||!is_array($boundary)||(string)($boundary['connection_id']??'')!==$sessionId||(string)($boundary['autocommit']??'')!=='1'||(string)($boundary['active']??'')!=='0')return self::error('transaction_active',503);
   if($database->query('START TRANSACTION')===false)return self::error('transaction',503);$started=true;
   if(!self::currentTransaction($database,$sessionId))return self::error('transaction_changed',503);
   $result=$callback();if(!self::currentTransaction($database,$sessionId))return self::error('transaction_changed',503);if($result instanceof WP_Error)return $result;
   if($finalAdmission!==null&&!$finalAdmission())return self::error('manual_reconciliation',403);if($wpdb!==$database)return self::error('transaction_changed',503);
   if($database->query('COMMIT')===false)return self::error('commit_unknown',503);
   $database->last_error='';$confirmed=$database->get_row('SELECT CONNECTION_ID() AS connection_id, @@session.autocommit AS autocommit, @@session.in_transaction AS active',ARRAY_A);
   if($wpdb!==$database||$database->last_error!==''||!is_array($confirmed)||(string)($confirmed['connection_id']??'')!==$sessionId||(string)($confirmed['autocommit']??'')!=='1'||(string)($confirmed['active']??'')!=='0')return self::error('commit_unknown',503);
   $started=false;$acknowledged=true;return $result;
  }catch(\Throwable){return self::error('persistence',503);}finally{
   if($started){$database->last_error='';$cleanup=$database->get_row('SELECT CONNECTION_ID() AS connection_id, @@session.in_transaction AS active',ARRAY_A);if($database->last_error===''&&is_array($cleanup)&&(string)($cleanup['connection_id']??'')===$sessionId&&(string)($cleanup['active']??'')==='1')$database->query('ROLLBACK');}
   $database->get_var($database->prepare('SELECT RELEASE_LOCK(%s)',$lock));
   // A durable decision remains committed; current admission still gates its private response.
   if($acknowledged&&$finalAdmission!==null){if(!$finalAdmission())return self::error('manual_reconciliation',403);if($wpdb!==$database)return self::error('transaction_changed',503);}
  }
 }
 private static function currentTransaction(\wpdb $database,string $sessionId):bool {
  global $wpdb;$database->last_error='';$frame=$database->get_row('SELECT CONNECTION_ID() AS connection_id, @@session.autocommit AS autocommit, @@session.in_transaction AS active',ARRAY_A);
  return $wpdb===$database&&$database->last_error===''&&is_array($frame)&&(string)($frame['connection_id']??'')===$sessionId&&(string)($frame['autocommit']??'')==='1'&&(string)($frame['active']??'')==='1';
 }
 private static function error(string $code,int $status):WP_Error{return new WP_Error('digiforge_printify_webhook_'.$code,'Webhook evidence unavailable.',['status'=>$status,'business_transition_authorized'=>false]);}
}
