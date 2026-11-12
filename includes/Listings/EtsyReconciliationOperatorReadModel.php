<?php
declare(strict_types=1);
namespace DigiForge\Listings;
/** Bounded operator projection of Etsy operations that require reconciliation. Read-only. */
final class EtsyReconciliationOperatorReadModel {
 /** Focus one reconciliation record outside the recent window, without exposing operation payloads. */
 public function byId(int $id, ?string &$queryState=null,?string $shop=null):?array {
  if($id<1){$queryState='INVALID_ID';return null;}
  global $wpdb;$table=$wpdb->prefix.'digiforge_etsy_operations';
  $wpdb->last_error='';
  $scope=$this->scope($table,$shop);
  $row=$wpdb->get_row($wpdb->prepare("SELECT id,shop_reference,operation_type,resource_reference,state,external_reference,reconciliation_reference,updated_at FROM $table WHERE id=%d AND state IN ('UNKNOWN','RECONCILIATION')".$scope,$id),ARRAY_A);
  if(!empty($wpdb->last_error)){$queryState='UNAVAILABLE';return null;}
  if(!is_array($row)){$queryState='NOT_FOUND';return null;}
  $queryState='AVAILABLE';
  return $row+['operator_action'=>'RECONCILE_BEFORE_ANY_RETRY','read_only'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false];
 }
 /** @return list<array<string,mixed>> */
 public function recent(int $limit=50, ?string &$queryState=null,?string $shop=null):array {
  global $wpdb;$limit=max(1,min(100,$limit));$table=$wpdb->prefix.'digiforge_etsy_operations';
  $wpdb->last_error='';
  $scope=$this->scope($table,$shop);
  $rows=$wpdb->get_results($wpdb->prepare("SELECT id,shop_reference,operation_type,resource_reference,state,external_reference,reconciliation_reference,updated_at FROM $table WHERE state IN ('UNKNOWN','RECONCILIATION')".$scope." ORDER BY id DESC LIMIT %d",$limit),ARRAY_A);
  if(!is_array($rows)||!empty($wpdb->last_error)){$queryState='UNAVAILABLE';return [];}
  $queryState='AVAILABLE';
  return array_map(static function(array $r):array{$r['operator_action']='RECONCILE_BEFORE_ANY_RETRY';$r['read_only']=true;$r['retry_permitted']=false;$r['external_execution_authorized']=false;return $r;},$rows);
 }
 private function scope(string $table,?string $shop):string {
  if($shop===null)return '';global $wpdb;$t=\DigiForge\Database\Tables::class;
  return $wpdb->prepare(' AND EXISTS (SELECT 1 FROM '.$t::etsy_draft_packages().' scope_package INNER JOIN '.$t::listings().' scope_listing ON scope_listing.id=scope_package.listing_id WHERE scope_package.id='.$table.'.draft_package_id AND BINARY scope_listing.shop_reference=BINARY %s AND '.EtsyStoredShopScope::matches($wpdb->prepare('%s',$shop),$table.'.shop_reference','scope_listing.environment').')',$shop);
 }
}
