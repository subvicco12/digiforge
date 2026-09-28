<?php
declare(strict_types=1);
namespace DigiForge\Listings;
/** Bounded operator projection of Etsy operations that require reconciliation. Read-only. */
final class EtsyReconciliationOperatorReadModel {
 /** @return list<array<string,mixed>> */
 public function recent(int $limit=50):array {
  global $wpdb;$limit=max(1,min(100,$limit));$table=$wpdb->prefix.'digiforge_etsy_operations';
  $rows=$wpdb->get_results($wpdb->prepare("SELECT id,shop_reference,operation_type,resource_reference,state,external_reference,reconciliation_reference,updated_at FROM $table WHERE state IN ('UNKNOWN','RECONCILIATION_REQUIRED') ORDER BY id DESC LIMIT %d",$limit),ARRAY_A);
  if(!is_array($rows))return [];
  return array_map(static function(array $r):array{$r['operator_action']='RECONCILE_BEFORE_ANY_RETRY';$r['read_only']=true;$r['retry_permitted']=false;$r['external_execution_authorized']=false;return $r;},$rows);
 }
}