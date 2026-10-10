<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use WP_Error;

final class MasterCatalogV2IngestionService {
 public function ingest(array $normalized,string $sourceSha256,string $versionLabel='v2-research-migration'):array|WP_Error {
  global $wpdb;
  if(!hash_equals(MasterCatalogV2Reference::SOURCE_SHA256,strtolower($sourceSha256))) return new WP_Error('v2_source_identity_mismatch','Master 500 v2 source workbook identity does not match the governed reference.');
  if(($normalized['catalog_key']??'')!==MasterCatalogV2Reference::CATALOG_KEY || !empty($normalized['production_authority']) || !empty($normalized['promotion_authorized'])) return new WP_Error('v2_contract_invalid','Master 500 v2 evidence must remain a non-authorizing migration candidate.');
  try {
   $master=[];$migration=[];
   foreach((array)($normalized['rows']??[]) as $row){
    if(!is_array($row)||array_keys($row)!==MasterCatalogV2MigrationContract::MASTER_REQUIRED)throw new \InvalidArgumentException('Invalid normalized master row');
    $master[]=array_values($row);
   }
   foreach((array)($normalized['migration']??[]) as $row){
    if(!is_array($row)||array_keys($row)!==MasterCatalogV2MigrationContract::MIGRATION_REQUIRED)throw new \InvalidArgumentException('Invalid normalized migration row');
    $migration[]=array_values($row);
   }
   $validated=MasterCatalogV2MigrationContract::normalize(MasterCatalogV2MigrationContract::MASTER_REQUIRED,$master,MasterCatalogV2MigrationContract::MIGRATION_REQUIRED,$migration);
   if(($normalized['row_count']??0)!==500||!hash_equals($validated['fingerprint'],(string)($normalized['fingerprint']??'')))throw new \InvalidArgumentException('Normalized fingerprint mismatch');
   $normalized=$validated;
  }catch(\Throwable $error){return new WP_Error('v2_contract_invalid','Master 500 v2 normalized evidence could not be independently revalidated.');}
  $wpdb->last_error='';
  $parent=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::catalog_versions().' WHERE catalog_key=%s AND source_sha256=%s AND source_state=%s ORDER BY id DESC LIMIT 1',PersonalizedCatalogReference::CATALOG_KEY,PersonalizedCatalogReference::SOURCE_SHA256,'IMMUTABLE_REFERENCE'),ARRAY_A);
  if(!is_array($parent)||!empty($wpdb->last_error)||(int)($parent['row_count']??0)!==PersonalizedCatalogReference::LISTING_COUNT||(int)($parent['production_authority']??1)!==0||!preg_match('/^[a-f0-9]{64}$/',(string)($parent['fingerprint']??''))) return new WP_Error('v1_parent_evidence_unavailable','Exact immutable Master 500 v1 parent evidence is required.');
  $mapped=$this->mapRows($normalized);
  if(is_wp_error($mapped)) return $mapped;
  $persistable=$normalized;$persistable['rows']=$mapped;
  $meta=['source_migration'=>$normalized['migration'],'source_state'=>'MIGRATION_CANDIDATE','parent_catalog_key'=>PersonalizedCatalogReference::CATALOG_KEY,'parent_version_id'=>(int)$parent['id'],'source_file'=>MasterCatalogV2Reference::SOURCE_FILE,'source_sha256'=>MasterCatalogV2Reference::SOURCE_SHA256,'fingerprint'=>(string)($normalized['fingerprint']??''),'production_authority'=>false,'promotion_authorized'=>false];
  return (new GovernedCatalogRepository())->ingest($persistable,$versionLabel,MasterCatalogV2Reference::SOURCE_SHA256,(int)$parent['id'],$meta,'MIGRATION_CANDIDATE');
 }
 private function mapRows(array $normalized):array|WP_Error {
  $bySource=[];foreach((array)($normalized['migration']??[]) as $m)$bySource[(string)($m['Source DG ID']??'')]=$m;
  $out=[];foreach((array)($normalized['rows']??[]) as $r){$source=(string)($r['Source DG ID']??'');$m=$source===''?[]:($bySource[$source]??null);if(!is_array($m)||($source!==''&&($m['V2 Successor ID']??'')!==($r['V2 ID']??'')))return new WP_Error('v2_lineage_unavailable','Master 500 v2 lineage is incomplete or inconsistent.');
   $out[]=['Listing ID'=>(string)$r['V2 ID'],'Family'=>(string)$r['Family'],'Concept'=>(string)$r['Concept'],'Engine'=>(string)$r['Engine'],'Physical Product'=>(string)$r['Physical Product'],'Supplier Gate'=>(string)$r['Supplier Gate'],'Template State'=>(string)$r['Template State'],'Wave'=>(string)($m['Wave']??''),'US Route'=>'','EU Route'=>'','Priority'=>'','Notes'=>(string)($m['Migration Note']??''),'V2 Evidence'=>['source_dg_id'=>$source,'origin'=>(string)$r['Origin'],'recommended_stage'=>(string)$r['Recommended Stage'],'v1_family'=>(string)($m['V1 Family']??''),'v1_concept'=>(string)($m['V1 Concept']??''),'migration_engine'=>(string)($m['Engine']??''),'disposition'=>(string)($m['Disposition']??''),'migration_note'=>(string)($m['Migration Note']??''),'retain_as_overlay_test'=>(string)($m['Retain as Overlay/Test']??'')]];
  } return count($out)===500?$out:new WP_Error('v2_row_count_invalid','Master 500 v2 requires exactly 500 persistable rows.');
 }
}
