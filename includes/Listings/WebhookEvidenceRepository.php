<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use DigiForge\Database\Tables;
use WP_Error;

/** Durable, non-authorizing evidence for verified inbound webhooks. */
final class WebhookEvidenceRepository {
 public function recordVerified(array $verified,string $body,array $headers):array|WP_Error{
  global $wpdb;$event=sanitize_text_field((string)($verified['event_id']??''));if($event==='')return new WP_Error('webhook_event_missing','Verified webhook event_id is required.');
  $identity=['provider'=>'etsy','event_id'=>$event,'event_type'=>sanitize_text_field((string)($verified['event_type']??'')),'shop_reference'=>sanitize_text_field((string)($verified['shop_reference']??$verified['shop_id']??$verified['payload']['shop_id']??$verified['payload']['shopId']??'')),'body_sha256'=>hash('sha256',$body)];
  $wpdb->last_error='';$existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::webhook_evidence().' WHERE provider=%s AND event_id=%s','etsy',$event),ARRAY_A);if(!empty($wpdb->last_error))return new WP_Error('webhook_evidence_unavailable','Webhook deduplication evidence could not be read.',['status'=>503]);if(is_array($existing))return $this->replay($existing,$identity);
  $safe=[];foreach($headers as $k=>$v){$name=strtolower((string)$k);if(in_array($name,['authorization','cookie','x-api-key'],true))continue;$safe[sanitize_key($name)]=is_scalar($v)?sanitize_text_field((string)$v):'[structured]';}
  $data=$identity+['verification_status'=>'VERIFIED','headers_evidence'=>wp_json_encode($safe),'validation_evidence'=>wp_json_encode(['verified_at'=>current_time('mysql',true)]),'processing_status'=>'VERIFIED','result_hash'=>'','received_at'=>current_time('mysql',true),'processed_at'=>null];
  if($wpdb->insert(Tables::webhook_evidence(),$data)!==1){$wpdb->last_error='';$winner=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::webhook_evidence().' WHERE provider=%s AND event_id=%s','etsy',$event),ARRAY_A);if(!empty($wpdb->last_error))return new WP_Error('webhook_evidence_unavailable','Webhook winner evidence could not be read after insert failure.',['status'=>503]);if(is_array($winner))return $this->replay($winner,$identity);return new WP_Error('webhook_evidence_failed','Webhook evidence could not be persisted.');}$id=(int)$wpdb->insert_id;$wpdb->last_error='';$created=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::webhook_evidence().' WHERE id=%d',$id),ARRAY_A);if(!empty($wpdb->last_error))return new WP_Error('webhook_evidence_unavailable','Persisted webhook evidence could not be read.',['status'=>503]);if(!is_array($created))return new WP_Error('webhook_evidence_unavailable','Persisted webhook evidence is unavailable after write.',['status'=>503]);return $created;
 }
 private function replay(array $stored,array $identity):array|WP_Error{
  foreach($identity as $key=>$value){if(!is_string($stored[$key]??null)||!hash_equals($value,$stored[$key]))return new WP_Error('webhook_evidence_conflict','Verified webhook event identity conflicts with immutable stored evidence.',['status'=>409,'reconciliation_required'=>true,'external_execution_performed'=>false]);}
  return $stored+['idempotent_replay'=>true];
 }
 public function markProcessed(string $eventId,string $resultHash):bool{global $wpdb;$wpdb->last_error='';$updated=$wpdb->update(Tables::webhook_evidence(),['processing_status'=>'PROCESSED','result_hash'=>$resultHash,'processed_at'=>current_time('mysql',true)],['provider'=>'etsy','event_id'=>$eventId]);return $updated===1&&empty($wpdb->last_error);}
 public function markFailed(string $eventId):bool{global $wpdb;$wpdb->last_error='';$updated=$wpdb->update(Tables::webhook_evidence(),['processing_status'=>'FAILED','processed_at'=>current_time('mysql',true)],['provider'=>'etsy','event_id'=>$eventId]);if($updated===false||$wpdb->last_error!=='')return false;if($updated===1)return true;if($updated!==0)return false;
  $wpdb->last_error='';$status=$wpdb->get_var($wpdb->prepare('SELECT processing_status FROM '.Tables::webhook_evidence().' WHERE provider=%s AND event_id=%s','etsy',$eventId));return $wpdb->last_error===''&&$status==='FAILED';}
}