<?php
declare(strict_types=1);
namespace DigiForge\Orders;
use DigiForge\Database\Tables;

/** Resolves one unique human-approved POD mapping; ambiguity fails closed. */
final class ApprovedPodMappingResolver {
 public static function resolve(int $productVersionId,string $environment):int {
  if($productVersionId<1||$environment==='')return 0;global $wpdb;
  $rows=$wpdb->get_col($wpdb->prepare("SELECT id FROM ".Tables::pod_mappings()." WHERE product_version_id=%d AND environment=%s AND state='APPROVED' AND approved_by>0 AND approved_at IS NOT NULL ORDER BY id ASC LIMIT 2",$productVersionId,$environment));
  return is_array($rows)&&count($rows)===1?(int)$rows[0]:0;
 }
 public static function isDigital(int $productVersionId):bool {
  if($productVersionId<1)return false;global $wpdb;return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::digital_products().' WHERE product_version_id=%d',$productVersionId))>0;
 }
}
