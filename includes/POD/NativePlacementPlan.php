<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use WP_Error;

/** Deterministic local geometry classification; no provider request or execution authority. */
final class NativePlacementPlan
{
 public function prepare(string $shop,string $bindingKey):array|WP_Error
 {
  $bindings=new ReviewedOwnershipBinding();$binding=$bindings->verified($shop,$bindingKey);if(is_wp_error($binding))return $binding;
  $payload=$binding['payload'];global $wpdb;$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_production_templates().' WHERE id=%d',$payload['production_template_id']),ARRAY_A);
  if($wpdb->last_error!=='')return self::error('template_unavailable',503);if(!is_array($row))return self::error('template_missing',409);
  try{
   foreach(['variant_ids','print_areas']as $field)$row[$field]=json_decode($row[$field],true,40,JSON_THROW_ON_ERROR);
   $template=ProductionTemplateContract::normalize($row);
   if(!hash_equals($payload['template_fingerprint'],$template['fingerprint'])||!hash_equals((string)$row['fingerprint'],$template['fingerprint'])||$template['template_id']!==$payload['template_id']||$template['template_version']!==$payload['template_version']||$template['supplier']!==$payload['provider'])return self::error('template_changed',409);
   $reasons=[];
   if($template['supplier']!=='printify')$reasons[]='PRINTIFY_TEMPLATE_REQUIRED';
   if($template['template_status']!=='VALIDATED')$reasons[]='VALIDATED_TEMPLATE_REQUIRED';
   if($template['personalization_pipeline']!=='PRINTIFY_NATIVE')$reasons[]='NATIVE_PIPELINE_REQUIRED';
   if(count($template['print_areas'])!==1)$reasons[]='SINGLE_PLACEMENT_REQUIRED';
   // Preserve the exact normalized geometry and provenance; no assumed placement coordinates or scale.
   $placements=$template['print_areas'];
   $current=$bindings->verified($shop,$bindingKey);if(is_wp_error($current))return $current;
   if($current['revision']!==$binding['revision']||!hash_equals($current['proposal_hash'],$binding['proposal_hash']))return self::error('binding_changed',409);
   return ['schema'=>'native-placement-plan-v1','shop_key'=>$shop,'environment'=>$payload['environment'],'binding_key'=>$bindingKey,'binding_revision'=>$binding['revision'],'binding_proposal_hash'=>$binding['proposal_hash'],'provider_mapping_id'=>$payload['provider_mapping_id'],'production_template_id'=>$payload['production_template_id'],'template_id'=>$template['template_id'],'template_version'=>$template['template_version'],'template_fingerprint'=>$template['fingerprint'],'provider'=>$template['supplier'],'provider_blueprint_id'=>$template['provider_blueprint_id'],'provider_id'=>$template['provider_id'],'variant_ids'=>$template['variant_ids'],'personalization_pipeline'=>$template['personalization_pipeline'],'placements'=>$placements,'classification'=>$reasons===[]?'SIMPLE':'REVIEW_REQUIRED','local_qualification'=>$reasons===[],'review_reasons'=>$reasons,'evidence_state'=>'SYNTHETIC','provider_identity_verified'=>false,'supplier_certified'=>false,'requires_external_certification'=>true,'external_execution_authorized'=>false,'read_only'=>true];
  }catch(\Throwable){return self::error('template_invalid',409);}
 }
 private static function error(string $code,int $status):WP_Error {return new WP_Error('digiforge_native_placement_'.$code,'A current reviewed ownership binding and exact stored production template are required.',['status'=>$status,'provider_identity_verified'=>false,'supplier_certified'=>false,'external_execution_authorized'=>false]);}
}
