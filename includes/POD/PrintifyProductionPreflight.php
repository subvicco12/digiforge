<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use DigiForge\Orders\Repository as OrderRepository;
use WP_Error;

/** Read-only Printify production preflight. Never calls a provider or authorizes execution. */
final class PrintifyProductionPreflight
{
 public function evaluate(int $packageId):array|WP_Error{
  global $wpdb;
  $package=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_authorization_packages().' WHERE id=%d',$packageId),ARRAY_A);
  if(!is_array($package))return new WP_Error('printify_preflight_package_missing','Authorization package not found.',['status'=>404]);
  $blockers=[];
  if((string)$package['state']!=='APPROVED_PACKAGE'||(int)$package['approved_by']<1||empty($package['approved_at']))$blockers[]='PACKAGE_HUMAN_APPROVAL_REQUIRED';
  if((int)$package['external_execution_authorized']!==0||(int)$package['external_execution_performed']!==0)$blockers[]='PACKAGE_EXECUTION_BOUNDARY_INVALID';
  $readiness=(new OrderRepository())->readiness((int)$package['order_id']);
  if(is_wp_error($readiness))return $readiness;
  $currentHash=hash('sha256',wp_json_encode($readiness));
  if(!hash_equals((string)$package['readiness_hash'],$currentHash))$blockers[]='ORDER_READINESS_STALE';
  if(empty($readiness['ready']))$blockers[]='ORDER_NOT_READY';
  $mapping=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_mappings().' WHERE id=%d',(int)$package['provider_mapping_id']),ARRAY_A);
  if(!is_array($mapping)||(string)($mapping['provider']??'')!=='printify'||(string)($mapping['state']??'')!=='APPROVED'||(int)($mapping['approved_by']??0)<1||empty($mapping['approved_at']))$blockers[]='PRINTIFY_MAPPING_NOT_CERTIFIED';
  $areas=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".Tables::pod_print_areas()." WHERE provider_mapping_id=%d AND state='APPROVED'",(int)$package['provider_mapping_id']));
  if($areas<1)$blockers[]='PRINTIFY_GEOMETRY_NOT_CERTIFIED';
  $ownership=(new BusinessScopeRepository())->assertActiveOwnershipForMapping((int)$package['provider_mapping_id']);
  if(is_wp_error($ownership)||(int)($ownership['id']??0)!==(int)$package['ownership_mapping_id'])$blockers[]='OWNERSHIP_MAPPING_STALE';
  $evidence=['package_id'=>$packageId,'package_hash'=>(string)$package['package_hash'],'order_id'=>(int)$package['order_id'],'provider_mapping_id'=>(int)$package['provider_mapping_id'],'readiness_hash'=>$currentHash,'approved_print_areas'=>$areas,'provider'=>is_array($mapping)?(string)$mapping['provider']:'','mapping_version'=>is_array($mapping)?(string)$mapping['mapping_version']:'','external_execution_authorized'=>false,'external_execution_performed'=>false];
  $evidence['preflight_hash']=hash('sha256',(string)wp_json_encode($evidence));
  return ['ready_for_dry_run'=>$blockers===[],'ready_for_external_execution'=>false,'blockers'=>$blockers,'evidence'=>$evidence];
 }
}
