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
  return self::transaction($dedupeKey,function()use($dedupeKey,$state,$expectedRevision,$note){global $wpdb;$receipt=self::read('digiforge_pfy_hook_'.$dedupeKey);if($receipt instanceof WP_Error)return $receipt;if($receipt===null)return self::error('missing',404);if(!self::anchored($receipt,$dedupeKey))return self::error('corrupt_receipt',503);$shop=(string)($receipt['shop_key']??'');if(!current_user_can('manage_digiforge_connections')||Owner::require($shop)!==true||ShopAccess::authorize($shop,'manage_digiforge_connections')!==true)return self::error('manual_reconciliation',403);$key='digiforge_pfy_hook_state_'.$dedupeKey;$old=self::read($key);if($old instanceof WP_Error)return $old;$revision=(int)($old['revision']??0);if($revision!==$expectedRevision)return self::error('revision',409);$attempts=(int)($old['attempts']??0);if($attempts<0||$attempts>3)return self::error('corrupt_state',503);$attempts=min(3,$attempts+($state==='RETRY_LOCAL'?1:0));if($state==='RETRY_LOCAL'&&$attempts>=3)$state='DEAD_LETTER';$next=['state'=>$state,'revision'=>$revision+1,'attempts'=>$attempts,'note'=>$note,'updated_at'=>gmdate('c'),'scheduled'=>false,'business_transition_authorized'=>false];$ok=$old===null?$wpdb->insert($wpdb->options,['option_name'=>$key,'option_value'=>wp_json_encode($next),'autoload'=>'no']):$wpdb->update($wpdb->options,['option_value'=>wp_json_encode($next)],['option_name'=>$key]);if($ok!==1||!Logger::write('printify_webhook_reconciled',$next,'printify_webhook',$dedupeKey))return self::error('reconciliation_store',503);return $next;});
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
 private static function transaction(string $key,callable $callback):array|WP_Error {
  global $wpdb;$wpdb->last_error='';$session=$wpdb->get_row('SELECT @@session.autocommit AS autocommit, @@session.in_transaction AS active',ARRAY_A);if($wpdb->last_error!==''||!is_array($session)||(string)($session['autocommit']??'')!=='1'||(string)($session['active']??'')!=='0')return self::error('transaction_active',503);
  foreach([$wpdb->options,Tables::audit_log()] as $table){$wpdb->last_error='';$engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));if($wpdb->last_error!==''||strtoupper((string)$engine)!=='INNODB')return self::error('durable_storage',503);}
  $lock='df_pfy_hook_'.substr($key,0,48);if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock))!=='1')return self::error('busy',503);
  $started=false;try{if($wpdb->query('START TRANSACTION')===false)return self::error('transaction',503);$started=true;$result=$callback();if($result instanceof WP_Error){$wpdb->query('ROLLBACK');$started=false;return $result;}if($wpdb->query('COMMIT')===false)return self::error('commit_unknown',503);$started=false;return $result;}catch(\Throwable){return self::error('persistence',503);}finally{if($started)$wpdb->query('ROLLBACK');$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
 }
 private static function error(string $code,int $status):WP_Error{return new WP_Error('digiforge_printify_webhook_'.$code,'Webhook evidence unavailable.',['status'=>$status,'business_transition_authorized'=>false]);}
}
