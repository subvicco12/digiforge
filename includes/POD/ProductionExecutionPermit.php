<?php
declare(strict_types=1);
namespace DigiForge\POD;

use WP_Error;

/**
 * Produces short-lived provider authorization evidence from a CURRENT preflight.
 * It never performs provider execution; adapters must independently verify it.
 */
final class ProductionExecutionPermit
{
 public function issue(int $packageId,string $requestFingerprint,string $nonce,int $ttlSeconds=300):array|WP_Error{
  $operator=(new ProductionPreflightOperatorReadModel())->project($packageId);if(is_wp_error($operator))return $operator;
  if(($operator['operator_state']??'')!=='PREFLIGHT_CURRENT')return new WP_Error('production_preflight_not_current','Current human-approved preflight evidence is required.',['status'=>409]);
  $requestFingerprint=strtolower(trim($requestFingerprint));
  if(!preg_match('/^[a-f0-9]{64}$/',$requestFingerprint))return new WP_Error('production_request_fingerprint_invalid','Exact provider request fingerprint is required.',['status'=>400]);
  $approval=[
   'state'=>'HUMAN_APPROVED',
   'decision'=>'APPROVE',
   'publishing_enabled'=>false,
   'order_execution_enabled'=>false,
   'evidence_hash'=>(string)$operator['preflight_hash'],
  ];
  $permit=ExecutionAuthorization::issue($approval,'PROVIDER_ORDER_SUBMIT',get_current_user_id(),$nonce,$ttlSeconds,$requestFingerprint);
  if(is_wp_error($permit))return $permit;
  return $permit+[
   'package_id'=>$packageId,
   'package_hash'=>(string)$operator['package_hash'],
   'preflight_hash'=>(string)$operator['preflight_hash'],
   'request_fingerprint'=>$requestFingerprint,
   'external_execution_performed'=>false,
  ];
 }
}
