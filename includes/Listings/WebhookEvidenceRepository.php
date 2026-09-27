<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use DigiForge\Database\Tables;
use WP_Error;

/** Durable, non-authorizing evidence for verified inbound webhooks. */
final class WebhookEvidenceRepository {
 public function recordVerified(array $verified,string $body,array $headers):array|WP_Error{
  global $wpdb;$event=sanitize_text_field((string)($verified['event_id']??''));if($event==='')return new WP_Error('webhook_event_missing','Verified webhook event_id is required.');
  $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::webhook_evidence().' WHERE provider=%s AND event_id=%s','etsy',$event),ARRAY_A);if(is_array($existing))return $existing+['idempotent_replay'=>true];
  $safe=[];foreach($headers as $k=>$v){$name=strtolower((string)$k);if(in_array($name,['authorization','cookie','x-api-key'],true))continue;$safe[sanitize_key($name)]=is_scalar($v)?sanitize_text_field((string)$v):'[structured]';}
  $data=['provider'=>'etsy','event_id'=>$event,'event_type'=>sanitize_text_field((string)($verified['event_type']??'')),'shop_reference'=>sanitize_text_field((string)($verified['shop_reference']??$verified['shop_id']??'')),'verification_status'=>'VERIFIED','body_sha256'=>hash('sha256',$body),'headers_evidence'=>wp_json_encode($safe),'validation_evidence'=>wp_json_encode(['verified_at'=>current_time('mysql',true)]),'processing_status'=>'VERIFIED','result_hash'=>'','received_at'=>current_time('mysql',true),'processed_at'=>null];
  if($wpdb->insert(Tables::webhook_evidence(),$data)!==1)return new WP_Error('webhook_evidence_failed','Webhook evidence could not be persisted.');return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::webhook_evidence().' WHERE id=%d',(int)$wpdb->insert_id),ARRAY_A)?:[];
 }
 public function markProcessed(string $eventId,string $resultHash):bool{global $wpdb;return $wpdb->update(Tables::webhook_evidence(),['processing_status'=>'PROCESSED','result_hash'=>$resultHash,'processed_at'=>current_time('mysql',true)],['provider'=>'etsy','event_id'=>$eventId])===1;}
 public function markFailed(string $eventId):bool{global $wpdb;return $wpdb->update(Tables::webhook_evidence(),['processing_status'=>'FAILED','processed_at'=>current_time('mysql',true)],['provider'=>'etsy','event_id'=>$eventId])===1;}
}