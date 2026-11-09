<?php
declare(strict_types=1);
namespace DigiForge\POD;
/** Inactive amendment, not a permit. No setting, exception or credential access is provided. */
final class PrintifyAcceptancePolicy {
 public static function proposal():array {
  return ['schema'=>'printify-get-policy-proposal-v1','status'=>'OWNER_APPROVAL_REQUIRED','enabled'=>false,'runtime_execution_authorized'=>false,'capability'=>'printify.acceptance.read','owner_actor_id'=>1,'shop_key'=>'personalized_pod','internal_shop_id'=>2,'integration_id'=>3,'provider_shop_id'=>'28930593','account_reference'=>null,'credential_reference'=>null,'expires_at'=>null,'maximum_lifetime_seconds'=>300,'maximum_requests'=>10,'maximum_per_rolling_minute'=>3,'maximum_page'=>3,'methods'=>['GET'],'allowlist_contract'=>'PrintifyAcceptanceContract','allowlist_source_sha256'=>hash_file('sha256',__DIR__.'/PrintifyAcceptanceContract.php'),'mutations_authorized'=>false,'changes_stop_all'=>false,'changes_external_lock'=>false,'changes_automation'=>false,'changes_release_hold'=>false,'required_reviews'=>['EXPLICIT_BLUEPRINT_SAFETY_AMENDMENT','EXACT_ACCOUNT_CREDENTIAL_SHOP_BINDING','INDEPENDENT_SESSION_REVIEW','SEPARATE_INSTALLATION_APPROVAL'],'implementation'=>'INACTIVE_VALIDATION_ONLY'];
 }
 /** Proposed capabilities cannot override global locks, even if described as approved. */
 public static function decision(string $method,bool $stopAll,bool $externalLock):array {
  return ['status'=>'BLOCKED_AUTHORITY','reason'=>!in_array($method,['GET'],true)?'PROVIDER_MUTATION_DENIED':($stopAll?'STOP_ALL':($externalLock?'EXTERNAL_LOCK':'AMENDMENT_NOT_ADOPTED')),'runtime_execution_authorized'=>false,'proposal_enabled'=>false,'blockers'=>array_values(array_filter([$method!=='GET'?'PROVIDER_MUTATION_DENIED':null,$stopAll?'STOP_ALL':null,$externalLock?'EXTERNAL_LOCK':null,'AMENDMENT_NOT_ADOPTED'])),'ordinary_provider_policy_unchanged'=>true];
 }
 public static function authorityBlockers(array $a,int $now,array $binding=[]):array {
  $b=[];if(($a['owner_actor_id']??null)!==1||($a['owner_verified']??null)!==true)$b[]='OWNER_AUTHORITY_INVALID';
  if(($a['shop_key']??null)!=='personalized_pod'||($a['integration_id']??null)!==3||($a['provider_shop_id']??null)!=='28930593')$b[]='AUTHORITY_SCOPE_MISMATCH';
  if(!is_string($a['account_reference']??null)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,190}$/D',$a['account_reference'])||in_array(strtoupper($a['account_reference']),['UNKNOWN','BLOCKED'],true)||($a['account_association_verified']??null)!==true||($binding['account_reference']??null)!==$a['account_reference'])$b[]='AUTHORITY_ACCOUNT_UNKNOWN';
  if(!is_string($a['credential_metadata_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$a['credential_metadata_sha256'])||($binding['credential_reference']['metadata_sha256']??null)!==$a['credential_metadata_sha256'])$b[]='AUTHORITY_CREDENTIAL_MISMATCH';
  if(($a['independent_review_valid']??null)!==true)$b[]='INDEPENDENT_REVIEW_MISSING';
  if(!is_bool($a['delegation_required']??null)||($a['delegation_required']&&($a['signed_delegation_valid']??null)!==true))$b[]='SIGNED_DELEGATION_INVALID';
  if(($a['revoked']??null)!==false)$b[]='AUTHORITY_REVOKED_OR_UNKNOWN';
  if(!is_int($a['issued_at']??null)||!is_int($a['expires_at']??null)||$a['issued_at']>$now||$a['expires_at']<=$now||$a['expires_at']-$a['issued_at']>300||$a['expires_at']<=$a['issued_at'])$b[]='AUTHORITY_EXPIRED_OR_INVALID';
  return $b;
 }
}
