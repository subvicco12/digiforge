<?php
declare(strict_types=1);
namespace DigiForge\POD;

use DigiForge\Database\Tables;
use WP_Error;

/** Read-only operator projection. It never grants or performs external execution. */
final class ProductionPreflightOperatorReadModel
{
 public function project(int $packageId):array|WP_Error{
  global $wpdb;
  $package=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_authorization_packages().' WHERE id=%d',$packageId),ARRAY_A);
  if(!empty($wpdb->last_error))return new WP_Error('production_preflight_evidence_unavailable','Production preflight operator evidence is unavailable; execution remains blocked.',['status'=>503,'external_execution_authorized'=>false,'external_execution_performed'=>false]);
  if(!is_array($package))return new WP_Error('production_preflight_package_missing','Authorization package not found.',['status'=>404]);
  $preflight=(new PrintifyProductionPreflight())->evaluate($packageId);if(is_wp_error($preflight))return $preflight;
  $approved=(string)$package['state']==='APPROVED_PACKAGE'&&(int)$package['approved_by']>0&&!empty($package['approved_at']);
  $current=!empty($preflight['ready_for_dry_run']);
  return [
   'package_id'=>$packageId,
   'order_id'=>(int)$package['order_id'],
   'package_state'=>(string)$package['state'],
   'package_human_approved'=>$approved,
   'preflight_current'=>$current,
   'operator_state'=>$approved?($current?'PREFLIGHT_CURRENT':'REVALIDATION_REQUIRED'):'HUMAN_REVIEW_REQUIRED',
   'blockers'=>(array)$preflight['blockers'],
   'package_hash'=>(string)$package['package_hash'],
   'preflight_hash'=>(string)$preflight['evidence']['preflight_hash'],
   'external_execution_authorized'=>false,
   'external_execution_performed'=>false,
  ];
 }
}
