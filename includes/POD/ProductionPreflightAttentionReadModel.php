<?php
declare(strict_types=1);
namespace DigiForge\POD;

use DigiForge\Database\Tables;

/** Evaluates approved packages against live preflight evidence; read-only and fail-closed. */
final class ProductionPreflightAttentionReadModel
{
 public function summary():array{
  global $wpdb;
  $ids=$wpdb->get_col("SELECT id FROM ".Tables::pod_authorization_packages()." WHERE state='APPROVED_PACKAGE' AND external_execution_performed=0 ORDER BY id ASC");
  $current=0;$revalidation=0;$errors=0;
  foreach((array)$ids as $id){
   $projection=(new ProductionPreflightOperatorReadModel())->project((int)$id);
   if(is_wp_error($projection)){$errors++;$revalidation++;continue;}
   if(($projection['operator_state']??'')==='PREFLIGHT_CURRENT')$current++;else $revalidation++;
  }
  return ['approved_packages'=>count((array)$ids),'preflight_current'=>$current,'revalidation_required'=>$revalidation,'evaluation_errors'=>$errors,'external_execution_performed'=>false];
 }
}
