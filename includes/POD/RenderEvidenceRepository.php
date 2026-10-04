<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use WP_Error;

/** Immutable certified render evidence; review never performs provider execution. */
final class RenderEvidenceRepository
{
 public function create(array $input):array|WP_Error{
  global $wpdb;$orderId=absint($input['order_id']??0);$mappingId=absint($input['provider_mapping_id']??0);
  $templateKey=sanitize_text_field((string)($input['template_key']??''));$version=sanitize_text_field((string)($input['template_version']??''));
  $templateHash=strtolower(trim((string)($input['template_sha256']??'')));$personalizationHash=strtolower(trim((string)($input['personalization_evidence_hash']??'')));$outputHash=strtolower(trim((string)($input['output_sha256']??'')));$previewHash=strtolower(trim((string)($input['buyer_preview_sha256']??'')));
  $mode=strtoupper(sanitize_key((string)($input['render_mode']??'')));if(!in_array($mode,['DETERMINISTIC','AI_ASSISTED','GENERATIVE'],true))return new WP_Error('invalid_render_mode','Certified render mode is required.');
  foreach([$templateHash,$personalizationHash,$outputHash,$previewHash] as $hash)if(!preg_match('/^[a-f0-9]{64}$/',$hash))return new WP_Error('invalid_render_evidence','SHA-256 render evidence is required.');
  if($orderId<1||$mappingId<1||$templateKey===''||$version==='')return new WP_Error('invalid_render_evidence','Order, mapping, and certified template identity are required.');if(!hash_equals($outputHash,$previewHash))return new WP_Error('render_preview_parity_failed','Buyer preview must be byte-identical to the production output evidence before review.',['status'=>409,'external_execution_authorized'=>false]);
  $wpdb->last_error='';$lineRaw=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::order_line_items().' WHERE order_id=%d AND provider_mapping_id=%d AND validation_status=%s',$orderId,$mappingId,'VALIDATED'));
  if(!empty($wpdb->last_error)||!is_numeric($lineRaw))return self::evidenceUnavailable();$lineCount=(int)$lineRaw;
  if($lineCount<1)return new WP_Error('render_mapping_not_on_order','Certified provider mapping must belong to a validated order line.',['status'=>409]);
  $wpdb->last_error='';$personalizationRaw=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".Tables::personalization_submissions()." ps INNER JOIN ".Tables::order_line_items()." li ON li.id=ps.order_line_item_id WHERE li.order_id=%d AND li.provider_mapping_id=%d AND ps.payload_hash=%s AND ps.review_status='APPROVED' AND ps.reviewed_by>0 AND ps.reviewed_at IS NOT NULL",$orderId,$mappingId,$personalizationHash));
  if(!empty($wpdb->last_error)||!is_numeric($personalizationRaw))return self::evidenceUnavailable();$personalizationCount=(int)$personalizationRaw;
  if($personalizationCount<1)return new WP_Error('render_personalization_not_approved','Render evidence must bind current approved personalization evidence for the mapped order line.',['status'=>409]);
  $canonical=wp_json_encode(['order_id'=>$orderId,'provider_mapping_id'=>$mappingId,'template_key'=>$templateKey,'template_version'=>$version,'template_sha256'=>$templateHash,'personalization_evidence_hash'=>$personalizationHash,'render_mode'=>$mode,'output_sha256'=>$outputHash,'buyer_preview_sha256'=>$previewHash]);$evidenceHash=hash('sha256',(string)$canonical);
  $wpdb->last_error='';$old=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_render_evidence().' WHERE evidence_hash=%s',$evidenceHash),ARRAY_A);if(!empty($wpdb->last_error))return self::evidenceUnavailable();if(is_array($old))return $old+['idempotent_replay'=>true];
  $ok=$wpdb->insert(Tables::pod_render_evidence(),['order_id'=>$orderId,'provider_mapping_id'=>$mappingId,'template_key'=>$templateKey,'template_version'=>$version,'template_sha256'=>$templateHash,'personalization_evidence_hash'=>$personalizationHash,'render_mode'=>$mode,'output_sha256'=>$outputHash,'evidence_hash'=>$evidenceHash,'review_status'=>'UNREVIEWED','reviewed_by'=>0,'reviewed_at'=>null,'external_execution_performed'=>0,'created_at'=>current_time('mysql',true)]);
  if($ok!==1){$wpdb->last_error='';$winner=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_render_evidence().' WHERE evidence_hash=%s',$evidenceHash),ARRAY_A);if(!empty($wpdb->last_error))return new WP_Error('render_evidence_race_unavailable','Concurrent render evidence could not be read; do not retry automatically.',['status'=>503,'retry_permitted'=>false,'external_execution_authorized'=>false]);if(is_array($winner))return $winner+['idempotent_replay'=>true];return new WP_Error('render_evidence_persistence_failed','Render evidence could not be persisted.',['status'=>500,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
  $wpdb->last_error='';$created=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_render_evidence().' WHERE id=%d',(int)$wpdb->insert_id),ARRAY_A);if(!is_array($created)||!empty($wpdb->last_error))return new WP_Error('render_evidence_confirmation_unavailable','Render evidence was persisted but confirmation evidence is unavailable; do not retry automatically.',['status'=>503,'retry_permitted'=>false,'external_execution_authorized'=>false]);return $created;
 }
 private static function evidenceUnavailable():WP_Error{return new WP_Error('render_evidence_unavailable','Render prerequisite evidence is unavailable; render certification is blocked.',['status'=>503,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
 public function approve(int $id):array|WP_Error{
  global $wpdb;$reviewer=get_current_user_id();if($reviewer<1)return new WP_Error('render_reviewer_required','Authenticated human visual reviewer required.',['status'=>403]);$now=current_time('mysql',true);
  $wpdb->last_error='';$ok=$wpdb->update(Tables::pod_render_evidence(),['review_status'=>'APPROVED','reviewed_by'=>$reviewer,'reviewed_at'=>$now],['id'=>$id,'review_status'=>'UNREVIEWED','external_execution_performed'=>0]);
  if($ok!==1){if(!empty($wpdb->last_error))return new WP_Error('render_review_persistence_failed','Render review could not be persisted.',['status'=>503,'retry_permitted'=>false,'external_execution_authorized'=>false]);return new WP_Error('render_review_conflict','Render evidence must be unreviewed and cannot be overwritten.',['status'=>409]);}
  $wpdb->last_error='';$approved=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_render_evidence().' WHERE id=%d',$id),ARRAY_A);if(!is_array($approved)||!empty($wpdb->last_error))return new WP_Error('render_review_confirmation_unavailable','Render review update completed but confirmation evidence is unavailable; do not retry automatically.',['status'=>503,'retry_permitted'=>false,'external_execution_authorized'=>false]);return $approved;
 }
}
