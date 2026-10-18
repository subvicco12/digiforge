<?php
declare(strict_types=1);
namespace DigiForge\Portal;
use DigiForge\Database\Tables;
/** Stored business/provider ownership only. Product ancestry scopes rows before the window. */
final class BusinessOwnershipReadModel {
 public function snapshot(string $shop,int $limit=50):array {
  $base=['shop'=>$shop,'query_state'=>'UNAVAILABLE','items'=>[],'read_only'=>true,'external_execution_authorized'=>false,'external_execution_performed'=>false,'credentials_exposed'=>false];
  if(!is_user_logged_in()||!current_user_can('manage_digiforge'))return array_replace($base,['query_state'=>'ACCESS_DENIED']);
  if(!isset(ShopOperationsReadModel::shops()[$shop]))return array_replace($base,['query_state'=>'SCOPE_INVALID']);
  foreach($shop===ShopOperationsReadModel::ALL?\DigiForge\Security\ShopAccessPolicy::canonicalShops():[$shop] as $required){if(is_wp_error(\DigiForge\Security\ShopAccess::authorize($required,'manage_digiforge')))return array_replace($base,['query_state'=>'ACCESS_DENIED']);}
  $base['connections']=[];foreach($shop===ShopOperationsReadModel::ALL?\DigiForge\Security\ShopAccessPolicy::canonicalShops():[$shop]as $selected){$base['connections'][$selected]=(new \DigiForge\POD\ScopedProviderConnection())->forShop($selected);}
  global $wpdb;$limit=max(1,min(100,$limit));$mapping=Tables::pod_mappings();
  $where=$shop===ShopOperationsReadModel::ALL?'':' WHERE pm.id IN (SELECT '.$mapping.'.id FROM '.$mapping.' WHERE '.ProductEvidenceScope::forTable($mapping,$shop).')';
  $scopeCases=[];foreach(\DigiForge\Security\ShopAccessPolicy::canonicalShops() as $canonical){$predicate=ProductEvidenceScope::forTable($mapping,$canonical);$scopeCases[]=$wpdb->prepare('WHEN pm.id IN (SELECT '.$mapping.'.id FROM '.$mapping.' WHERE '.$predicate.') THEN %s',$canonical);}
  $canonicalScope='CASE '.implode(' ',$scopeCases)." ELSE '' END";
  $claimCount='(SELECT COUNT(*) FROM '.Tables::pod_business_mappings().' ownership_claim WHERE ownership_claim.provider_mapping_id=pm.id)';
  $sql='SELECT '.$canonicalScope.' AS canonical_shop,'.$claimCount.' AS ownership_claim_count,own.id AS ownership_id,own.state AS ownership_state,own.approved_by AS ownership_approved_by,own.approved_at AS ownership_approved_at,b.id AS business_id,b.business_key,b.display_name AS business_name,b.status AS business_status,s.id AS store_id,s.store_key,s.display_name AS store_name,s.channel,s.status AS store_status,p.id AS program_id,p.program_key,p.status AS program_status,pm.id AS provider_mapping_id,pm.product_version_id,pm.provider,pm.environment,pm.provider_product_key,pm.provider_variant_key,pm.mapping_version,pm.state AS mapping_state,pm.approved_by AS mapping_approved_by,pm.approved_at AS mapping_approved_at FROM '.Tables::pod_business_mappings().' own INNER JOIN '.Tables::businesses().' b ON b.id=own.business_id INNER JOIN '.Tables::stores().' s ON s.id=own.store_id AND s.business_id=b.id INNER JOIN '.Tables::product_programs().' p ON p.id=own.product_program_id AND p.business_id=b.id AND p.store_id=s.id INNER JOIN '.$mapping.' pm ON pm.id=own.provider_mapping_id AND pm.product_version_id=own.product_version_id'.$where.' ORDER BY own.id DESC LIMIT %d';
  $wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare($sql,$limit),ARRAY_A);
  if(!is_array($rows)||!empty($wpdb->last_error))return $base;
  foreach($rows as &$row){$row['evidence_state']=self::evidenceState($row);$row['provider_connection_binding']='NOT_ESTABLISHED';$row['external_execution_authorized']=false;}unset($row);
  return array_replace($base,['query_state'=>'AVAILABLE','items'=>$rows]);
 }
 public static function evidenceState(array $row):string {
  if(array_key_exists('ownership_claim_count',$row)&&(int)$row['ownership_claim_count']!==1)return 'AMBIGUOUS_OWNERSHIP';
  if(array_key_exists('canonical_shop',$row)&&!\DigiForge\Security\ShopAccessPolicy::validShop($row['canonical_shop']))return 'UNVERIFIED_SHOP_ANCESTRY';
  foreach(['business_status','store_status','program_status'] as $field)if(($row[$field]??'')!=='ACTIVE')return 'INACTIVE_OWNERSHIP';
  foreach(['ownership','mapping'] as $prefix)if(($row[$prefix.'_state']??'')!=='APPROVED'||(int)($row[$prefix.'_approved_by']??0)<1||empty($row[$prefix.'_approved_at']))return 'REVIEW_REQUIRED';
  return 'APPROVED_LOCAL_EVIDENCE';
 }
}
