<?php
declare(strict_types=1);
namespace DigiForge\Orders;
use DigiForge\Database\Tables;
/** Read-only queue for explicit personalization and POD ownership human gates. */
final class HumanGateOperationsReadModel{
 public function snapshot(int $limit=50, ?string $shop=null):array{
  global $wpdb;$limit=max(1,min(100,$limit));$wpdb->last_error='';$out=['personalization'=>[],'ownership'=>[],'query_state'=>['personalization'=>'AVAILABLE','ownership'=>'AVAILABLE'],'external_execution_authorized'=>false,'retry_permitted'=>false];
  $personalizationScope=$shop===null?'':$wpdb->prepare(' AND EXISTS (SELECT 1 FROM '.Tables::orders().' o WHERE o.id=li.order_id AND BINARY o.shop_reference=BINARY %s)',$shop);
  $ownershipScope=$shop===null?'':$wpdb->prepare(' AND EXISTS (SELECT 1 FROM '.Tables::product_versions().' pv INNER JOIN '.Tables::products().' product ON product.id=pv.product_id INNER JOIN '.Tables::product_families().' family ON family.id=product.product_family_id INNER JOIN '.Tables::research_candidates().' candidate ON candidate.opportunity_id=family.opportunity_id WHERE pv.id='.Tables::pod_business_mappings().'.product_version_id AND BINARY CASE WHEN JSON_VALID(candidate.score_inputs) THEN JSON_UNQUOTE(JSON_EXTRACT(candidate.score_inputs,%s)) ELSE NULL END=BINARY %s AND NOT EXISTS (SELECT 1 FROM '.Tables::research_candidates().' conflicting WHERE conflicting.opportunity_id=family.opportunity_id AND BINARY COALESCE(CASE WHEN JSON_VALID(conflicting.score_inputs) THEN JSON_UNQUOTE(JSON_EXTRACT(conflicting.score_inputs,%s)) ELSE NULL END,%s)<>BINARY %s))','$.shop_key',$shop,'$.shop_key','',$shop);
  $p=$wpdb->get_results($wpdb->prepare('SELECT p.id,p.order_line_item_id,li.order_id,p.personalization_schema_id,p.payload_hash,p.review_status,p.environment,p.created_at FROM '.Tables::personalization_submissions().' p LEFT JOIN '.Tables::order_line_items().' li ON li.id=p.order_line_item_id WHERE p.review_status NOT IN (%s,%s)'.$personalizationScope.' ORDER BY p.id DESC LIMIT %d','APPROVED','REJECTED',$limit),ARRAY_A);
  if($p===null||!empty($wpdb->last_error)){$out['personalization']=[];$out['query_state']['personalization']='UNAVAILABLE';$wpdb->last_error='';}else $out['personalization']=$p;
  $wpdb->last_error='';
  $o=$wpdb->get_results($wpdb->prepare('SELECT id,business_id,store_id,product_program_id,product_version_id,provider_mapping_id,state,request_fingerprint,created_at FROM '.Tables::pod_business_mappings().' WHERE state=%s'.$ownershipScope.' ORDER BY id DESC LIMIT %d','DRAFT',$limit),ARRAY_A);
  if($o===null||!empty($wpdb->last_error)){$out['ownership']=[];$out['query_state']['ownership']='UNAVAILABLE';$wpdb->last_error='';}else $out['ownership']=$o;
  $out['query_state']['aggregate']=in_array('UNAVAILABLE',$out['query_state'],true)?'PARTIAL_UNAVAILABLE':'AVAILABLE';
  return $out;
 }
}
