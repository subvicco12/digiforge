<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
use DigiForge\POD\PrintifyApiReadWorkflow;
/** Actual core governed-read producer/source join. No business version, epoch or grant inferred. */
final class NativeOrderSourceEvidence {
 public static function resolve(ShopBinding $scope,int $actor,string $receiptHash):array|\WP_Error {
  if(preg_match('/^[a-f0-9]{64}$/D',$receiptHash)!==1)return NativeFailure::error('source_identity');
  $current=NativeAuthorityAdapter::scope($scope,$actor,['manage_digiforge_orders']);if(is_wp_error($current))return $current;
  try{
   $w=new PrintifyApiReadWorkflow();$source=$w->orderSource($receiptHash);if(is_wp_error($source))return $source;$r=$source['receipt'];
   if(NativePortContract::hash($source['connection_scope'])!==NativePortContract::hash($current['scope'])||$r['shop_key']!==$scope->shopKey||$r['integration_id']!==$scope->integrationId||$r['provider_shop_id']!==$scope->providerShopId||$r['connection_binding_hash']!==$scope->bindingHash||$r['external_account_hash']!==hash('sha256',$current['scope']['external_account_reference']))return NativeFailure::error('source_scope');
   // Exact companion OrderReadModel physical-scope rule; identity input is sealed core data.
   $identity=NativePortContract::hash([[$scope->shopKey,$scope->integrationId,$scope->providerShopId,$scope->environment],$r['data']['id']]);
   $again=$w->orderSource($receiptHash);$last=NativeAuthorityAdapter::scope($scope,$actor,['manage_digiforge_orders']);
   if(is_wp_error($again)||is_wp_error($last)||$again!==$source||$last['scope']!==$current['scope']||$last['session_fingerprint']!==$current['session_fingerprint'])return NativeFailure::error('source_changed');
   $out=['schema'=>'DF_NATIVE_ORDER_SOURCE_1','scope_fingerprint'=>$scope->fingerprint(),'actor_id'=>$actor,'order_identity'=>$identity,'immutable_source_version'=>$receiptHash,'source_content_hash'=>NativePortContract::hash($r['data']),'plan_hash'=>$r['plan_hash'],'authorization_hash'=>$source['authorization_hash'],'approval_hash'=>$source['approval_hash'],'approval_revision'=>$source['approval_revision'],'producer_kind'=>'CORE_GOVERNED_ORDER_READ','producer_audit_id'=>$source['producer_audit_id'],'producer_actor_id'=>$source['producer_actor_id'],'observed_at'=>$r['observed_at'],'line_items'=>$r['data']['line_items'],'shipments'=>$r['data']['shipments'],'native_business_version_verified'=>false,'native_dispatch_receipt_verified'=>false,'independent_checkpoint_verified'=>false,'operational_grant_issued'=>false,'external_execution_authorized'=>false];
   $out['evidence_hash']=NativePortContract::hash($out);return $out;
  }catch(\Throwable){return NativeFailure::error('source_unavailable');}
 }
}
