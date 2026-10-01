<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;

/** Bounded, fail-closed operator projection for buyer-specific render evidence. */
final class RenderEvidenceOperationsReadModel
{
 public function snapshot(int $limit=50):array{
  global $wpdb;$limit=max(1,min(100,$limit));
  $wpdb->last_error='';
  $rows=$wpdb->get_results($wpdb->prepare('SELECT id,order_id,provider_mapping_id,template_key,template_version,render_mode,review_status,reviewed_by,reviewed_at,external_execution_performed,created_at FROM '.Tables::pod_render_evidence().' ORDER BY id DESC LIMIT %d',$limit),ARRAY_A);
  if(!is_array($rows)||!empty($wpdb->last_error))return ['query_state'=>'UNAVAILABLE','counts'=>['renders'=>null,'unreviewed'=>null,'approved'=>null],'items'=>[],'external_execution_authorized'=>false,'external_execution_performed'=>false];
  $counts=['renders'=>0,'unreviewed'=>0,'approved'=>0];$items=[];
  foreach($rows as $row){$counts['renders']++;$status=(string)($row['review_status']??'UNKNOWN');if($status==='UNREVIEWED')$counts['unreviewed']++;elseif($status==='APPROVED')$counts['approved']++;
   $items[]=['render_evidence_id'=>(int)$row['id'],'order_id'=>(int)$row['order_id'],'provider_mapping_id'=>(int)$row['provider_mapping_id'],'template_key'=>(string)$row['template_key'],'template_version'=>(string)$row['template_version'],'render_mode'=>(string)$row['render_mode'],'review_status'=>$status,'human_reviewed'=>(int)($row['reviewed_by']??0)>0&&!empty($row['reviewed_at']),'external_execution_authorized'=>false,'external_execution_performed'=>(int)($row['external_execution_performed']??0)===1];
  }
  return ['query_state'=>'AVAILABLE','counts'=>$counts,'items'=>$items,'bounded_limit'=>$limit,'external_execution_authorized'=>false,'external_execution_performed'=>false];
 }
}
