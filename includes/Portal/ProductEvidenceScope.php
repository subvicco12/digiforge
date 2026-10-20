<?php
declare(strict_types=1);
namespace DigiForge\Portal;
use DigiForge\Database\Tables;
/** Read-only stored ancestry; absent or conflicting ownership cannot establish a shop. */
final class ProductEvidenceScope {
 public static function forTable(string $table,string $shop):?string {
  if($table===Tables::production_qa()){
   $targets=['spec'=>Tables::asset_specs(),'plan'=>Tables::production_plans(),'revision'=>Tables::asset_revisions(),'bundle'=>Tables::release_bundles()];$parts=[];
   foreach($targets as $type=>$parent)$parts[]="(".$table.".target_type='".$type."' AND EXISTS (SELECT 1 FROM ".$parent." WHERE ".$parent.".id=".$table.".target_id AND ".self::forTable($parent,$shop)."))";
   return '('.implode(' OR ',$parts).')';
  }
  $opportunity=match($table){
   Tables::opportunities()=>$table.'.id',
   Tables::product_families()=>$table.'.opportunity_id',
   Tables::products()=>'(SELECT f.opportunity_id FROM '.Tables::product_families().' f WHERE f.id='.$table.'.product_family_id)',
   default=>null,
  };
  if($opportunity!==null)return self::opportunity($opportunity,$shop);
  $version=match($table){
   Tables::product_versions()=>$table.'.id',
   Tables::digital_products(),Tables::digital_files(),Tables::digital_packages(),Tables::asset_specs(),Tables::production_plans(),Tables::pod_mappings(),Tables::personalization_schemas()=>$table.'.product_version_id',
   Tables::digital_previews(),Tables::digital_templates(),Tables::digital_licenses(),Tables::digital_download_checks()=>'(SELECT dp.product_version_id FROM '.Tables::digital_products().' dp WHERE dp.id='.$table.'.digital_product_id)',
   Tables::digital_file_versions()=>'(SELECT df.product_version_id FROM '.Tables::digital_files().' df WHERE df.id='.$table.'.digital_file_id)',
   Tables::asset_revisions()=>'(SELECT s.product_version_id FROM '.Tables::asset_specs().' s WHERE s.id='.$table.'.asset_spec_id)',
   Tables::production_intents(),Tables::release_bundles()=>'(SELECT plan.product_version_id FROM '.Tables::production_plans().' plan WHERE plan.id='.$table.'.production_plan_id)',
   Tables::pod_print_areas(),Tables::pod_provider_intents(),Tables::pod_cost_snapshots(),Tables::pod_readiness_reviews()=>'(SELECT m.product_version_id FROM '.Tables::pod_mappings().' m WHERE m.id='.$table.'.provider_mapping_id)',
   default=>null,
  };
  if($version===null)return null;
  return self::version($version,$shop);
 }
 private static function version(string $expression,string $shop):string {
  return 'EXISTS (SELECT 1 FROM '.Tables::product_versions().' scope_version INNER JOIN '.Tables::products().' scope_product ON scope_product.id=scope_version.product_id INNER JOIN '.Tables::product_families().' scope_family ON scope_family.id=scope_product.product_family_id WHERE scope_version.id='.$expression.' AND '.self::opportunity('scope_family.opportunity_id',$shop).')';
 }
 private static function opportunity(string $expression,string $shop):string {
  global $wpdb;
  $owner=static fn(string $alias):string=>'CASE WHEN JSON_VALID('.$alias.'.score_inputs) THEN JSON_UNQUOTE(JSON_EXTRACT('.$alias.'.score_inputs,\'$.shop_key\')) ELSE NULL END';
  return $wpdb->prepare('EXISTS (SELECT 1 FROM '.Tables::research_candidates().' scope_candidate WHERE scope_candidate.opportunity_id='.$expression.' AND BINARY '.$owner('scope_candidate').' = BINARY %s) AND NOT EXISTS (SELECT 1 FROM '.Tables::research_candidates().' scope_conflict WHERE scope_conflict.opportunity_id='.$expression.' AND BINARY COALESCE('.$owner('scope_conflict').',\'\') <> BINARY %s)',$shop,$shop);
 }
}
