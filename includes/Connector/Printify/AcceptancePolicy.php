<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
/** Non-authorizing policy assessment. Evidence supplied here can NEVER grant a request. */
final class AcceptancePolicy {
 public static function assess(array $evidence):array {
  $expected=['method'=>'GET','shop_key'=>'personalized_pod','integration_id'=>3,'provider_shop_id'=>'28930593','environment'=>'production','stop_all'=>false,'external_lock'=>false,'native_provider_enabled'=>true,'binding_reviewed'=>true,'credential_association_verified'=>true,'installation_approved'=>true,'live_get_approved'=>true];
  $blockers=[];
  if(array_diff(array_keys($evidence),array_keys($expected)))$blockers[]='UNEXPECTED_EVIDENCE';
  foreach($expected as $field=>$required)if(!array_key_exists($field,$evidence)||$evidence[$field]!==$required)$blockers[]=strtoupper($field);
  return ['policy'=>'PR1262_STAGE2_NATIVE_GET_V1','execution_authorized'=>false,'eligible_for_controlled_review'=>$blockers===[],'blockers'=>$blockers,'mutation_authorized'=>false,'automatic_retry'=>false];
 }
}
