<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use WP_Error;

/** Persists validated catalog versions without granting production authority. */
final class GovernedCatalogRepository {
 public function ingest(array $normalized,string $versionLabel,string $sourceSha256,int $parentVersionId=0,array $migration=[],string $sourceState='IMMUTABLE_REFERENCE'):array|WP_Error{
  global $wpdb;$catalogKey=sanitize_key((string)($normalized['catalog_key']??''));$fingerprint=strtolower((string)($normalized['fingerprint']??''));
  if($catalogKey===''||!preg_match('/^[a-f0-9]{64}$/',$sourceSha256)||!preg_match('/^[a-f0-9]{64}$/',$fingerprint)||count((array)($normalized['rows']??[]))!==500)return new WP_Error('invalid_catalog_evidence','Governed catalog evidence is invalid.');
  $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::catalog_versions().' WHERE catalog_key=%s AND version_label=%s',$catalogKey,$versionLabel),ARRAY_A);
  if(is_array($existing)){
   $expectedState=in_array($sourceState,['IMMUTABLE_REFERENCE','MIGRATION_CANDIDATE'],true)?$sourceState:'';
   $storedMigration=json_decode((string)($existing['migration_metadata']??''),true);
   $same=hash_equals((string)$existing['fingerprint'],$fingerprint)&&hash_equals((string)$existing['source_sha256'],$sourceSha256)&&$expectedState!==''&&((string)($existing['source_state']??''))===$expectedState&&(int)($existing['parent_version_id']??0)===max(0,$parentVersionId)&&(int)($existing['row_count']??0)===500&&(int)($existing['production_authority']??1)===0&&is_array($storedMigration)&&hash_equals(hash('sha256',(string)wp_json_encode($storedMigration)),hash('sha256',(string)wp_json_encode($migration)));
   if($same)return $existing+['idempotent_replay'=>true];
   return new WP_Error('immutable_version_conflict','Catalog version labels are immutable and replay evidence must match exactly.',[],409);
  }
  if(!in_array($sourceState,['IMMUTABLE_REFERENCE','MIGRATION_CANDIDATE'],true))return new WP_Error('invalid_catalog_source_state','Governed catalog source state is invalid.');
  $now=current_time('mysql',true);$wpdb->last_error='';$started=$wpdb->query('START TRANSACTION');if($started===false||!empty($wpdb->last_error))return new WP_Error('catalog_transaction_start_failed','Catalog transaction could not be started; nothing was persisted.');$ok=$wpdb->insert(Tables::catalog_versions(),['catalog_key'=>$catalogKey,'version_label'=>sanitize_text_field($versionLabel),'source_sha256'=>$sourceSha256,'source_state'=>$sourceState,'parent_version_id'=>max(0,$parentVersionId),'migration_metadata'=>wp_json_encode($migration),'row_count'=>500,'fingerprint'=>$fingerprint,'production_authority'=>0,'created_by'=>get_current_user_id(),'created_at'=>$now]);
  if($ok!==1){$rollback=$wpdb->query('ROLLBACK');if($rollback===false)return new WP_Error('catalog_rollback_unknown','Catalog transaction rollback could not be confirmed; reconcile durable state before retry.',['status'=>503,'retry_permitted'=>false,'external_execution_authorized'=>false]);return new WP_Error('catalog_version_insert_failed','Catalog version could not be persisted.');}
  $versionId=(int)$wpdb->insert_id;
  foreach((array)$normalized['rows'] as $row){$payload=['family'=>(string)$row['Family'],'concept'=>(string)$row['Concept'],'engine'=>(string)$row['Engine'],'physical_product'=>(string)$row['Physical Product'],'supplier_gate'=>(string)$row['Supplier Gate'],'template_state'=>(string)$row['Template State'],'wave'=>(string)$row['Wave'],'us_route'=>(string)$row['US Route'],'eu_route'=>(string)$row['EU Route'],'priority'=>(string)$row['Priority'],'notes'=>(string)$row['Notes'],'lineage'=>(array)($row['V2 Evidence']??[])];$canonical=wp_json_encode($payload);if($wpdb->insert(Tables::catalog_items(),['catalog_version_id'=>$versionId,'listing_id'=>(string)$row['Listing ID'],'family'=>$payload['family'],'concept'=>$payload['concept'],'engine'=>$payload['engine'],'physical_product'=>$payload['physical_product'],'supplier_gate'=>$payload['supplier_gate'],'template_state'=>$payload['template_state'],'attributes'=>$canonical,'row_hash'=>hash('sha256',(string)$canonical),'created_at'=>$now])!==1){$rollback=$wpdb->query('ROLLBACK');if($rollback===false)return new WP_Error('catalog_rollback_unknown','Catalog transaction rollback could not be confirmed; reconcile durable state before retry.',['status'=>503,'retry_permitted'=>false,'external_execution_authorized'=>false]);return new WP_Error('catalog_item_insert_failed','Catalog item persistence failed; no partial catalog version was retained.');}}
  $wpdb->last_error='';$persisted=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::catalog_versions().' WHERE id=%d',$versionId),ARRAY_A);if(!is_array($persisted)||!empty($wpdb->last_error)){$rollback=$wpdb->query('ROLLBACK');if($rollback===false)return new WP_Error('catalog_rollback_unknown','Catalog transaction rollback could not be confirmed; reconcile durable state before retry.',['status'=>503,'retry_permitted'=>false,'external_execution_authorized'=>false]);return new WP_Error('catalog_version_verify_failed','Catalog version could not be verified after persistence.');}$wpdb->last_error='';$committed=$wpdb->query('COMMIT');if($committed===false||!empty($wpdb->last_error))return new WP_Error('catalog_commit_unknown','Catalog commit result is unknown and requires reconciliation before retry.');return $persisted;
 }
 public function browse(int $versionId,array $filters=[],int $limit=50,int $offset=0):array{
  global $wpdb;$where=['catalog_version_id=%d'];$args=[$versionId];
  foreach(['family','engine','template_state'] as $field){if(!empty($filters[$field])){$where[]="$field=%s";$args[]=sanitize_text_field((string)$filters[$field]);}}
  $limit=min(100,max(1,$limit));$offset=max(0,$offset);$args[]=$limit;$args[]=$offset;
  return $wpdb->get_results($wpdb->prepare('SELECT * FROM '.Tables::catalog_items().' WHERE '.implode(' AND ',$where).' ORDER BY listing_id ASC LIMIT %d OFFSET %d',...$args),ARRAY_A)?:[];
 }
}