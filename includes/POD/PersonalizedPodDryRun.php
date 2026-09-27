<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;

/** Local certification projection. It deliberately has no HTTP/provider dependency. */
final class PersonalizedPodDryRun
{
 public function certify(int $packageId):array|WP_Error{
  $preflight=(new PrintifyProductionPreflight())->evaluate($packageId);if(is_wp_error($preflight))return $preflight;
  $evidence=(array)$preflight['evidence'];
  $payload=['package_id'=>$packageId,'package_hash'=>(string)$evidence['package_hash'],'preflight_hash'=>(string)$evidence['preflight_hash'],'provider'=>'printify','provider_mapping_id'=>(int)$evidence['provider_mapping_id'],'mapping_version'=>(string)$evidence['mapping_version'],'readiness_hash'=>(string)$evidence['readiness_hash'],'intent_type'=>'PROVIDER_ORDER_SUBMIT','intent_state'=>'BLOCKED','network_execution_performed'=>false,'external_execution_authorized'=>false,'external_execution_performed'=>false,'retry_permitted'=>false,'reconciliation_required'=>false];
  $payload['dry_run_hash']=hash('sha256',(string)wp_json_encode($payload));
  return ['certified'=>(bool)$preflight['ready_for_dry_run'],'preflight'=>$preflight,'execution_intent'=>$payload];
 }
}
