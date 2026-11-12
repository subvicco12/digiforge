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
  $wpdb->last_error='';
  $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::catalog_versions().' WHERE catalog_key=%s AND version_label=%s',$catalogKey,$versionLabel),ARRAY_A);
  if(!empty($wpdb->last_error))return new WP_Error('catalog_evidence_unavailable','Existing catalog evidence could not be read.',['status'=>503]);
  if(is_array($existing)){
   $expectedState=in_array($sourceState,['IMMUTABLE_REFERENCE','MIGRATION_CANDIDATE'],true)?$sourceState:'';
   $storedMigration=json_decode((string)($existing['migration_metadata']??''),true);
   if(is_array($storedMigration)&&array_key_exists('catalog_item_order',$storedMigration))$migration['catalog_item_order']=array_column((array)$normalized['rows'],'Listing ID');
   $same=hash_equals((string)$existing['fingerprint'],$fingerprint)&&hash_equals((string)$existing['source_sha256'],$sourceSha256)&&$expectedState!==''&&((string)($existing['source_state']??''))===$expectedState&&(int)($existing['parent_version_id']??0)===max(0,$parentVersionId)&&(int)($existing['row_count']??0)===500&&(int)($existing['production_authority']??1)===0&&is_array($storedMigration)&&hash_equals(hash('sha256',(string)wp_json_encode($storedMigration)),hash('sha256',(string)wp_json_encode($migration)));
   if($same){$items=self::verifyVersionEvidence($existing,(array)$normalized['rows']);if(is_wp_error($items))return $items;return $existing+['idempotent_replay'=>true];}
   return new WP_Error('immutable_version_conflict','Catalog version labels are immutable and replay evidence must match exactly.',[],409);
  }
  if(!in_array($sourceState,['IMMUTABLE_REFERENCE','MIGRATION_CANDIDATE'],true))return new WP_Error('invalid_catalog_source_state','Governed catalog source state is invalid.');
  $migration['catalog_item_order']=array_column((array)$normalized['rows'],'Listing ID');
  $now=current_time('mysql',true);$wpdb->last_error='';$started=$wpdb->query('START TRANSACTION');if($started===false||!empty($wpdb->last_error))return new WP_Error('catalog_transaction_start_failed','Catalog transaction could not be started; nothing was persisted.');$ok=$wpdb->insert(Tables::catalog_versions(),['catalog_key'=>$catalogKey,'version_label'=>sanitize_text_field($versionLabel),'source_sha256'=>$sourceSha256,'source_state'=>$sourceState,'parent_version_id'=>max(0,$parentVersionId),'migration_metadata'=>wp_json_encode($migration),'row_count'=>500,'fingerprint'=>$fingerprint,'production_authority'=>0,'created_by'=>get_current_user_id(),'created_at'=>$now]);
  if($ok!==1){$rollback=$wpdb->query('ROLLBACK');if($rollback===false)return new WP_Error('catalog_rollback_unknown','Catalog transaction rollback could not be confirmed; reconcile durable state before retry.',['status'=>503,'retry_permitted'=>false,'external_execution_authorized'=>false]);return new WP_Error('catalog_version_insert_failed','Catalog version could not be persisted.');}
  $versionId=(int)$wpdb->insert_id;
  foreach((array)$normalized['rows'] as $row){$payload=self::rowPayload($row);$canonical=wp_json_encode($payload);if($wpdb->insert(Tables::catalog_items(),['catalog_version_id'=>$versionId,'listing_id'=>(string)$row['Listing ID'],'family'=>$payload['family'],'concept'=>$payload['concept'],'engine'=>$payload['engine'],'physical_product'=>$payload['physical_product'],'supplier_gate'=>$payload['supplier_gate'],'template_state'=>$payload['template_state'],'attributes'=>$canonical,'row_hash'=>hash('sha256',(string)$canonical),'created_at'=>$now])!==1){$rollback=$wpdb->query('ROLLBACK');if($rollback===false)return new WP_Error('catalog_rollback_unknown','Catalog transaction rollback could not be confirmed; reconcile durable state before retry.',['status'=>503,'retry_permitted'=>false,'external_execution_authorized'=>false]);return new WP_Error('catalog_item_insert_failed','Catalog item persistence failed; no partial catalog version was retained.');}}
  $wpdb->last_error='';$persisted=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::catalog_versions().' WHERE id=%d',$versionId),ARRAY_A);$persistedReadFailed=!is_array($persisted)||!empty($wpdb->last_error);$items=$persistedReadFailed?null:self::verifyVersionEvidence($persisted,(array)$normalized['rows']);if($persistedReadFailed||is_wp_error($items)){$rollback=$wpdb->query('ROLLBACK');if($rollback===false)return new WP_Error('catalog_rollback_unknown','Catalog transaction rollback could not be confirmed; reconcile durable state before retry.',['status'=>503,'retry_permitted'=>false,'external_execution_authorized'=>false]);if(is_wp_error($items))return $items;return new WP_Error('catalog_version_verify_failed','Catalog version could not be verified after persistence.');}$wpdb->last_error='';$committed=$wpdb->query('COMMIT');if($committed===false||!empty($wpdb->last_error))return new WP_Error('catalog_commit_unknown','Catalog commit result is unknown and requires reconciliation before retry.');return $persisted;
 }
 public function browse(int $versionId,array $filters=[],int $limit=50,int $offset=0):array|WP_Error{
  global $wpdb;$where=['catalog_version_id=%d'];$args=[$versionId];
  foreach(['family','engine','template_state'] as $field){if(!empty($filters[$field])){$where[]="$field=%s";$args[]=sanitize_text_field((string)$filters[$field]);}}
  $limit=min(100,max(1,$limit));$offset=max(0,$offset);$args[]=$limit;$args[]=$offset;
  $wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Tables::catalog_items().' WHERE '.implode(' AND ',$where).' ORDER BY listing_id ASC LIMIT %d OFFSET %d',...$args),ARRAY_A);
  if(!is_array($rows)||!empty($wpdb->last_error))return new WP_Error('catalog_evidence_unavailable','Catalog browsing evidence could not be read.',['status'=>503]);
  return $rows;
 }
 /** Verify the durable child evidence, never just its advertised header count. */
 public static function verifyStoredItems(int $versionId,?array $expectedRows=null):array|WP_Error {
  global $wpdb;$wpdb->last_error='';
  $rows=$wpdb->get_results($wpdb->prepare('SELECT catalog_version_id,listing_id,family,concept,engine,physical_product,supplier_gate,template_state,attributes,row_hash FROM '.Tables::catalog_items().' WHERE catalog_version_id=%d ORDER BY listing_id ASC LIMIT 501',$versionId),ARRAY_A);
  if(!is_array($rows)||!empty($wpdb->last_error))return new WP_Error('catalog_evidence_unavailable','Stored catalog item evidence could not be read.',['status'=>503,'query_state'=>'UNAVAILABLE']);
  $invalid=static fn()=>new WP_Error('catalog_item_evidence_conflict','Stored catalog items are incomplete or differ from canonical evidence.',['status'=>409,'query_state'=>'AVAILABLE']);
  if(count($rows)!==500)return $invalid();
  $expected=[];
  if($expectedRows!==null){foreach($expectedRows as $row){$id=(string)($row['Listing ID']??'');if(isset($expected[$id]))return $invalid();$expected[$id]=wp_json_encode(self::rowPayload($row));}if(count($expected)!==500)return $invalid();}
  $seen=[];$keys=['family','concept','engine','physical_product','supplier_gate','template_state','wave','us_route','eu_route','priority','notes','lineage'];
  foreach($rows as $row){
   $id=(string)($row['listing_id']??'');$json=(string)($row['attributes']??'');
   if($id===''||isset($seen[$id])||(int)($row['catalog_version_id']??0)!==$versionId)return $invalid();$seen[$id]=true;
   try{$payload=json_decode($json,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){return $invalid();}
   if(!is_array($payload)||array_keys($payload)!==$keys||!is_array($payload['lineage'])||wp_json_encode($payload)!==$json||!hash_equals(hash('sha256',$json),(string)($row['row_hash']??'')))return $invalid();
   foreach(array_slice($keys,0,11) as $key)if(!is_string($payload[$key]))return $invalid();
   foreach(array_slice($keys,0,6) as $key)if((string)($row[$key]??'')!==$payload[$key])return $invalid();
   if($expectedRows!==null&&(!isset($expected[$id])||$expected[$id]!==$json))return $invalid();
  }
  return $rows;
 }
 /** Bind canonical children back to the exact normalized version fingerprint. */
 public static function verifyVersionEvidence(array $version,?array $expectedRows=null):array|WP_Error {
  $items=self::verifyStoredItems((int)($version['id']??0),$expectedRows);if(is_wp_error($items))return $items;
  $invalid=static fn()=>new WP_Error('catalog_item_evidence_conflict','Stored catalog content does not reconstruct the exact version fingerprint.',['status'=>409,'query_state'=>'AVAILABLE']);
  try{
   $key=(string)($version['catalog_key']??'');$rows=[];
   $meta=json_decode((string)($version['migration_metadata']??'[]'),true,32,JSON_THROW_ON_ERROR);if(!is_array($meta))return $invalid();
   if(array_key_exists('catalog_item_order',$meta)){$order=$meta['catalog_item_order'];if(!is_array($order)||!array_is_list($order)||count($order)!==500)return $invalid();foreach($order as $id)if(!is_string($id))return $invalid();if(count(array_unique($order))!==500)return $invalid();$byId=[];foreach($items as $item)$byId[$item['listing_id']]=$item;$ordered=[];foreach($order as $id){if(!is_string($id)||!isset($byId[$id]))return $invalid();$ordered[]=$byId[$id];}$items=$ordered;}
   // Legacy original sheets use listing-ID order; unknown legacy ordering fails closed.
   if($key===PersonalizedCatalogReference::CATALOG_KEY){
    foreach($items as $item){$p=json_decode((string)$item['attributes'],true,32,JSON_THROW_ON_ERROR);$rows[]=[(string)$item['listing_id'],$p['wave'],$p['family'],$p['concept'],$p['engine'],$p['physical_product'],$p['supplier_gate'],$p['template_state'],$p['us_route'],$p['eu_route'],$p['priority'],$p['notes']];if($p['lineage']!==[])return $invalid();}
    $normalized=MasterCatalogImportContract::normalize(MasterCatalogImportContract::REQUIRED,$rows,$key);
   }elseif($key===MasterCatalogV2Reference::CATALOG_KEY){
    $migration=$meta['source_migration']??null;if(!is_array($migration))return $invalid();$bySource=[];
    foreach($migration as $m){if(!is_array($m)||array_keys($m)!==MasterCatalogV2MigrationContract::MIGRATION_REQUIRED)return $invalid();$bySource[$m['Source DG ID']]=$m;}
    foreach($items as $item){
     $p=json_decode((string)$item['attributes'],true,32,JSON_THROW_ON_ERROR);$l=$p['lineage'];$source=(string)($l['source_dg_id']??'');$m=$source===''?[]:($bySource[$source]??null);if(!is_array($m))return $invalid();
     $lineage=['source_dg_id'=>$source,'origin'=>(string)($l['origin']??''),'recommended_stage'=>(string)($l['recommended_stage']??''),'v1_family'=>(string)($m['V1 Family']??''),'v1_concept'=>(string)($m['V1 Concept']??''),'migration_engine'=>(string)($m['Engine']??''),'disposition'=>(string)($m['Disposition']??''),'migration_note'=>(string)($m['Migration Note']??''),'retain_as_overlay_test'=>(string)($m['Retain as Overlay/Test']??'')];
     if($lineage!==$l||$p['wave']!==(string)($m['Wave']??'')||$p['notes']!==(string)($m['Migration Note']??'')||$p['us_route']!==''||$p['eu_route']!==''||$p['priority']!=='')return $invalid();
     $rows[]=[(string)$item['listing_id'],$source,$p['family'],$p['concept'],$p['engine'],$p['physical_product'],$p['supplier_gate'],$p['template_state'],$l['origin'],$l['recommended_stage']];
    }
    $normalized=MasterCatalogV2MigrationContract::normalize(MasterCatalogV2MigrationContract::MASTER_REQUIRED,$rows,MasterCatalogV2MigrationContract::MIGRATION_REQUIRED,array_map('array_values',$migration));
   }else{return $invalid();}
   if(!hash_equals((string)($version['fingerprint']??''),$normalized['fingerprint']))return $invalid();
  }catch(\Throwable){return $invalid();}
  return $items;
 }
 private static function rowPayload(array $row):array {
  return ['family'=>(string)$row['Family'],'concept'=>(string)$row['Concept'],'engine'=>(string)$row['Engine'],'physical_product'=>(string)$row['Physical Product'],'supplier_gate'=>(string)$row['Supplier Gate'],'template_state'=>(string)$row['Template State'],'wave'=>(string)$row['Wave'],'us_route'=>(string)$row['US Route'],'eu_route'=>(string)$row['EU Route'],'priority'=>(string)$row['Priority'],'notes'=>(string)$row['Notes'],'lineage'=>(array)($row['V2 Evidence']??[])];
 }

}