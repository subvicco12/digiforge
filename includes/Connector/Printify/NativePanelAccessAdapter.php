<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;

/** Authentication only. Does not mount a route, grant review authority or export auth values. */
final class NativePanelAccessAdapter
{
 public static function nonceAction(ShopBinding $scope,string $view):string {
  if(!in_array($view,['publication','orders','fulfillment','conflicts','unknown'],true))throw new \InvalidArgumentException('Fixed panel view required.');
  return 'digiforge_printify_panel:1.0.0:'.$scope->fingerprint().':'.$view;
 }
 public static function access(ShopBinding $scope,int $actor,array $context):array|\WP_Error {
  if(!NativePortContract::valid('panel_context',$context))return NativeFailure::error('panel_contract');
  $caps=match($context['view']){'publication'=>['manage_digiforge_listings'], 'orders','fulfillment'=>['manage_digiforge_orders'], default=>['manage_digiforge_listings','manage_digiforge_orders']};
  $current=NativeAuthorityAdapter::scope($scope,$actor,$caps);if(is_wp_error($current))return $current;
  try{
   if(!hash_equals($current['session_fingerprint'],$context['session_fingerprint'])||wp_verify_nonce($context['csrf_token'],self::nonceAction($scope,$context['view']))===false)return NativeFailure::error('panel_nonce_or_session');
   // Recheck current session and independently reviewed scope after validating CSRF.
   $last=NativeAuthorityAdapter::scope($scope,$actor,$caps);if(is_wp_error($last)||$last['scope']!==$current['scope']||!hash_equals($last['session_fingerprint'],$current['session_fingerprint']))return NativeFailure::error('panel_revoked');
   $receipt=['schema'=>'DF_NATIVE_PANEL_ACCESS_1','scope_fingerprint'=>$scope->fingerprint(),'actor_id'=>$actor,'view'=>$context['view'],'session_fingerprint'=>$current['session_fingerprint'],'csrf_valid'=>true,'access_granted'=>true,'authorization_revision'=>$scope->revision,'expires_at'=>$current['expires_at'],'read_only'=>true,'external_execution_authorized'=>false];
   $receipt['receipt_hash']=NativePortContract::hash($receipt);
   return NativePortContract::valid('panel_access',$receipt)?$receipt:NativeFailure::error('panel_response_contract');
  }catch(\Throwable){return NativeFailure::error('panel_unavailable');}
 }
}
