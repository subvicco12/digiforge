<?php
declare(strict_types=1);
namespace DigiForge\POD;
/** Pure, non-authorizing evidence contract. Caller booleans are NEVER runtime authority. */
final class PrintifyAcceptanceOperationalPolicy {
 public const CAPABILITY='printify.acceptance.read';
 public const REVISION1_SHA256='ffbbfe63ecc4223d3192c7fc01a8a33e872cfb51f78a896696f2537465526c9e';
 public static function evaluate(array $e,int $now):array {
  $expected=['capability'=>self::CAPABILITY,'method'=>'GET','policy_sha256'=>self::REVISION1_SHA256,'implementation_certified'=>true,'installation_authorized'=>true,'operational_authorized'=>true,'independent_review_valid'=>true,'owner_actor_id'=>1,'owner_verified'=>true,'session_bound'=>true,'binding_verified'=>true,'credential_association_verified'=>true,'internal_shop_id'=>2,'shop_key'=>'personalized_pod','integration_id'=>3,'provider_shop_id'=>'28930593','environment'=>'production','integration_enabled'=>false,'stop_all'=>true,'external_lock'=>true,'automation_armed'=>false,'activation_authorized'=>false,'release_hold'=>true,'generation_current'=>true,'audit_available'=>true];
  $blockers=[];foreach($expected as $k=>$v)if(($e[$k]??null)!==$v)$blockers[]='EVIDENCE_'.strtoupper($k);
  if(array_diff(array_keys($e),array_merge(array_keys($expected),['issued_at','expires_at'])))$blockers[]='UNSUPPORTED_EVIDENCE';
  if(!is_int($e['issued_at']??null)||!is_int($e['expires_at']??null)||$e['issued_at']>$now||$e['expires_at']<=$now||$e['expires_at']<=$e['issued_at']||$e['expires_at']-$e['issued_at']>300)$blockers[]='SESSION_LIFETIME';
  return ['schema'=>'printify-acceptance-operational-evidence-v1','eligible'=>!$blockers,'blockers'=>$blockers,'runtime_execution_authorized'=>false,'credential_access_authorized'=>false,'evidence_is_caller_supplied'=>true];
 }
}
