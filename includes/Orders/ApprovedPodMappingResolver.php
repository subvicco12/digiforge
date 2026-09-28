<?php
declare(strict_types=1);
namespace DigiForge\Orders;
use DigiForge\Database\Tables;
use WP_Error;

/** Resolves one unique human-approved POD mapping; ambiguity and unavailable evidence fail closed. */
final class ApprovedPodMappingResolver {
 public static function resolve(int $productVersionId,string $environment):int|WP_Error {
  if($productVersionId<1||$environment==='')return 0;global $wpdb;
  $rows=$wpdb->get_col($wpdb->prepare("SELECT id FROM ".Tables::pod_mappings()." WHERE product_version_id=%d AND environment=%s AND state='APPROVED' AND approved_by>0 AND approved_at IS NOT NULL ORDER BY id ASC LIMIT 2",$productVersionId,$environment));
  if(!is_array($rows)||!empty($wpdb->last_error))return new WP_Error('pod_mapping_evidence_unavailable','Approved POD mapping evidence is unavailable; fulfillment mapping is blocked.',['status'=>503,'external_execution_authorized'=>false]);
  return count($rows)===1?(int)$rows[0]:0;
 }
 public static function isDigital(int $productVersionId):bool|WP_Error {
  if($productVersionId<1)return false;global $wpdb;$raw=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::digital_products().' WHERE product_version_id=%d',$productVersionId));
  if(!empty($wpdb->last_error)||!is_numeric($raw))return new WP_Error('product_type_evidence_unavailable','Product type evidence is unavailable; fulfillment classification is blocked.',['status'=>503,'external_execution_authorized'=>false]);
  return (int)$raw>0;
 }
}
