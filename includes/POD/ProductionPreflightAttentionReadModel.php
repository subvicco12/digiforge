<?php
declare(strict_types=1);
namespace DigiForge\POD;

use DigiForge\Database\Tables;

/** Evaluates approved packages against live preflight evidence; read-only and fail-closed. */
final class ProductionPreflightAttentionReadModel
{
 public function summary():array{
  global $wpdb;
  $wpdb->last_error='';
  $ids=$wpdb->get_col("SELECT id FROM ".Tables::pod_authorization_packages()." WHERE state='APPROVED_PACKAGE' AND external_execution_performed=0 ORDER BY id ASC");
  if(!is_array($ids)||!empty($wpdb->last_error))return ['approved_packages'=>null,'preflight_current'=>null,'revalidation_required'=>null,'evaluation_errors'=>null,'query_state'=>'UNAVAILABLE','external_execution_performed'=>false];
  $current=0;$revalidation=0;$errors=0;
  foreach($ids as $id){
   $projection=(new ProductionPreflightOperatorReadModel())->project((int)$id);
   if(is_wp_error($projection)){$errors++;$revalidation++;continue;}
   if(($projection['operator_state']??'')==='PREFLIGHT_CURRENT')$current++;else $revalidation++;
  }
  return ['approved_packages'=>count($ids),'preflight_current'=>$current,'revalidation_required'=>$revalidation,'evaluation_errors'=>$errors,'query_state'=>'AVAILABLE','external_execution_performed'=>false];
 }
}
