<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
use DigiForge\POD\{PrintifyProductPipeline,PrintifyConnectionBoundary};
use DigiForge\Security\ReviewedLocalEvidence;
use DigiForge\Listings\Validator;
/** Read-only joins to protected server records. Output is evidence, never an operational grant. */
final class NativePlanEvidence {
 public static function productPlan(ShopBinding $scope,int $actor,string $hash):array|\WP_Error {
  if(!preg_match('/^[a-f0-9]{64}$/D',$hash)||$scope->shopKey!=='personalized_pod')return NativeFailure::error('plan_identity');
  $current=NativeAuthorityAdapter::scope($scope,$actor,['manage_digiforge_pod','manage_digiforge_products','manage_digiforge_listings']);if(is_wp_error($current))return $current;
  try{
   $pipeline=new PrintifyProductPipeline();$plan=$pipeline->plan($hash);$expected=$pipeline->approvalPayload($hash);$policy=ReviewedLocalEvidence::approved('policy',$scope->shopKey,'printify-product:'.$hash);
   if(is_wp_error($plan)||is_wp_error($expected)||is_wp_error($policy)||($plan['binding_state']??null)!=='VERIFIED_LOCAL_BINDING'||($plan['integration_id']??null)!==$scope->integrationId||($plan['shop_id']??null)!==$scope->providerShopId||($plan['environment']??null)!==$scope->environment||Validator::canonicalJson($expected)!==Validator::canonicalJson($policy['payload']))return NativeFailure::error('current_plan_approval_required');
   $connection=PrintifyConnectionBoundary::context($scope->shopKey,$scope->integrationId,PrintifyProductPipeline::CAPABILITY);
   if(is_wp_error($connection)||!is_array($plan['connection_scope']??null)||Validator::canonicalJson($connection)!==Validator::canonicalJson($plan['connection_scope']))return NativeFailure::error('plan_connection_revision');
   foreach(['installation_sha256','environment','business_id','store_id','program_id','shop_key','provider','external_account_reference','external_shop_reference','integration_id']as $key)if(($connection[$key]??null)!==($current['scope'][$key]??null))return NativeFailure::error('plan_connection_identity');
   $last=NativeAuthorityAdapter::scope($scope,$actor,['manage_digiforge_pod','manage_digiforge_products','manage_digiforge_listings']);$again=ReviewedLocalEvidence::approved('policy',$scope->shopKey,'printify-product:'.$hash);$lastConnection=PrintifyConnectionBoundary::context($scope->shopKey,$scope->integrationId,PrintifyProductPipeline::CAPABILITY);$lastPlan=$pipeline->plan($hash);
   if(is_wp_error($last)||is_wp_error($again)||is_wp_error($lastConnection)||is_wp_error($lastPlan)||$lastConnection!==$connection||$lastPlan!==$plan||$last['scope']!==$current['scope']||$again!==$policy)return NativeFailure::error('plan_approval_changed');
   $r=['schema'=>'DF_NATIVE_LOCAL_PLAN_EVIDENCE_1','scope_fingerprint'=>$scope->fingerprint(),'actor_id'=>$actor,'plan_hash'=>$hash,'workflow_hash'=>$plan['workflow'],'package_hash'=>$plan['package'],'connection_binding_hash'=>$connection['binding_hash'],'request_fingerprint'=>$plan['request']['fingerprint'],'operation'=>$plan['request']['operation'],'approval_hash'=>$policy['proposal_hash'],'approval_revision'=>$policy['revision'],'approval_expires_at'=>$policy['expires_at'],'native_product_order_version_join_verified'=>false,'independent_checkpoint_verified'=>false,'operational_grant_issued'=>false,'external_execution_authorized'=>false];$r['evidence_hash']=NativePortContract::hash($r);return $r;
  }catch(\Throwable){return NativeFailure::error('plan_evidence_unavailable');}
 }
 public static function receipt(ShopBinding $scope,int $actor,string $hash):array|\WP_Error {
  if(!preg_match('/^[a-f0-9]{64}$/D',$hash))return NativeFailure::error('receipt_identity');
  $a=NativeAuthorityAdapter::scope($scope,$actor,['manage_digiforge_products','manage_digiforge_listings']);if(is_wp_error($a))return $a;
  try{$r=(new PrintifyProductPipeline())->receipt($hash);if(is_wp_error($r)||($r['status']??null)!=='SUCCEEDED'||($r['audit_required']??null)!==true||($r['integration_id']??null)!==$scope->integrationId||($r['shop_id']??null)!==$scope->providerShopId)return NativeFailure::error('trusted_core_receipt_required');
   $p=self::productPlan($scope,$actor,$r['plan_hash']);if(is_wp_error($p))return $p;
   if($p['workflow_hash']!==$r['workflow']||$p['package_hash']!==$r['package']||($r['connection_binding_hash']??null)!==$p['connection_binding_hash']||($r['operation']??null)!==$p['operation'])return NativeFailure::error('receipt_plan_identity');
   $out=['schema'=>'DF_NATIVE_LOCAL_RECEIPT_REFERENCE_1','receipt_hash'=>$hash,'scope_fingerprint'=>$scope->fingerprint(),'actor_id'=>$actor,'plan_evidence_hash'=>$p['evidence_hash'],'native_producer_fence_join_verified'=>false,'external_execution_authorized'=>false];$out['evidence_hash']=NativePortContract::hash($out);return $out;
  }catch(\Throwable){return NativeFailure::error('receipt_evidence_unavailable');}
 }
}
