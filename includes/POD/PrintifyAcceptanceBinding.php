<?php
declare(strict_types=1);
namespace DigiForge\POD;
/** Pure proposal/evidence validation. A positive result NEVER provisions a connection or execution grant. */
final class PrintifyAcceptanceBinding {
 public static function proposal():array {
  return ['schema'=>'printify-shop2-binding-proposal-v2','status'=>'PROPOSAL_ONLY','internal_shop_id'=>2,'shop_key'=>'personalized_pod','integration_id'=>3,'provider'=>'printify','provider_shop_id'=>'28930593','excluded_provider_shop_id'=>'28925875','channel'=>'etsy','environment'=>'production','account_reference'=>null,'credential_reference'=>null,'catalog_scope'=>null,'evidence_revision'=>null,'approval_sha256'=>null,'observed_at'=>null,'expires_at'=>null,'integration_enabled'=>false,'runtime_execution_authorized'=>false];
 }
 public static function validate(array $p,array $e,int $now):array {
  $b=[];if(count($p)!==count(self::proposal())||array_diff(array_keys($p),array_keys(self::proposal())))$b[]='INVALID_PROPOSAL_FIELDS';if(($p['schema']??null)!=='printify-shop2-binding-proposal-v2'||($p['excluded_provider_shop_id']??null)!=='28925875')$b[]='PROPOSAL_SCHEMA_OR_EXCLUSION_INVALID';if(!is_bool($p['integration_enabled']??null)||$p['integration_enabled']!==($e['integration_enabled']??null))$b[]='INTEGRATION_STATE_DRIFT';if(($p['runtime_execution_authorized']??null)!==false||($p['status']??null)!=='PROPOSAL_ONLY')$b[]='PROPOSAL_CANNOT_GRANT_AUTHORITY';
  foreach(['internal_shop_id'=>2,'shop_key'=>'personalized_pod','integration_id'=>3,'provider'=>'printify','provider_shop_id'=>'28930593','environment'=>'production','channel'=>'etsy'] as $k=>$v)if(($p[$k]??null)!==$v)$b[]=match($k){'provider_shop_id'=>'PROVIDER_SHOP_MISMATCH','environment'=>'WRONG_ENVIRONMENT',default=>'IDENTITY_'.strtoupper($k)};
  $account=$p['account_reference']??null;if(!is_string($account)||(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,190}$/D',$account)||in_array(strtoupper($account),['UNKNOWN','BLOCKED'],true))||($e['account_association_verified']??null)!==true||($e['account_reference']??null)!==$account)$b[]='ACCOUNT_ASSOCIATION_UNKNOWN';
  $c=$p['credential_reference']??null;
  if(!is_array($c)||array_diff(array_keys($c),['integration_id','secret_name','metadata_sha256'])||($c['integration_id']??null)!==3||!in_array($c['secret_name']??null,['personal_access_token','access_token'],true)||!self::hash($c['metadata_sha256']??null)||($e['credential_reference_present']??null)!==true||($e['credential_metadata_sha256']??null)!==($c['metadata_sha256']??null))$b[]='CREDENTIAL_REFERENCE_UNKNOWN';
  if(($e['credential_account_association_verified']??null)!==true)$b[]='CREDENTIAL_ACCOUNT_ASSOCIATION_UNKNOWN';
  $scope=$p['catalog_scope']??null;if(!is_array($scope)||array_diff(array_keys($scope),['business_id','store_id','product_program'])||($scope['product_program']??null)!=='PERSONALIZED_POD'||!is_int($scope['business_id']??null)||$scope['business_id']<1||!is_int($scope['store_id']??null)||$scope['store_id']<1||($e['registry_scope_verified']??null)!==true)$b[]='CATALOG_SCOPE_MISSING';
  if(($e['duplicate_mapping']??null)!==false)$b[]='DUPLICATE_MAPPING_OR_UNKNOWN';
  if(($e['cross_shop_conflict']??null)!==false)$b[]='CROSS_SHOP_CONFLICT_OR_UNKNOWN';
  if(($e['provider_scope_verified']??null)!==true)$b[]='PROVIDER_SCOPE_UNVERIFIED';
  if(($e['integration_enabled']??null)!==true)$b[]='INTEGRATION_DISABLED_OR_UNKNOWN';
  if(!is_int($p['evidence_revision']??null)||$p['evidence_revision']<1||($e['evidence_revision']??null)!==$p['evidence_revision']||!self::hash($p['approval_sha256']??null)||($e['approval_sha256']??null)!==$p['approval_sha256']||($e['independent_approval_valid']??null)!==true)$b[]='UNREVIEWED_BINDING';
  if(($e['authority_revoked']??null)!==false)$b[]='REVOKED_OR_UNKNOWN_AUTHORITY';
  if(!is_int($p['observed_at']??null)||$p['observed_at']>$now||$p['observed_at']<$now-300||!is_int($p['expires_at']??null)||$p['expires_at']<=$now||$p['expires_at']>$p['observed_at']+3600)$b[]='STALE_OR_EXPIRED_EVIDENCE';
  return ['schema'=>'printify-binding-validation-v1','status'=>$b?'BLOCKED_PROVIDER':'OFFLINE_PROPOSAL_VALIDATED','blockers'=>array_values(array_unique($b)),'runtime_execution_authorized'=>false,'binding_write_authorized'=>false,'integration_enablement_authorized'=>false,'evidence_is_caller_supplied'=>true];
 }
 private static function hash(mixed $s):bool{return is_string($s)&&preg_match('/^[a-f0-9]{64}$/D',$s)===1;}
}
