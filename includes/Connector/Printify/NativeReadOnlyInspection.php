<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
/** Local inspection only. No route, authority issuance, epoch production or mutation dispatch. */
final class NativeReadOnlyInspection {
 public const VERSION='1.0.0';
 public static function snapshot(ShopBinding $scope,int $actor,array $context,array $request):array|\WP_Error {
  try{
   $kind=$request['kind']??null;$keys=match($kind){'READINESS'=>['kind'],'HELD_OPERATION'=>['kind','operation_id'],'ORDER_OBSERVATIONS'=>['kind','limit'],default=>null};
   if($keys===null||array_diff(array_keys($request),$keys)||array_diff($keys,array_keys($request))||($context['view']??null)!==($kind==='ORDER_OBSERVATIONS'?'orders':'unknown'))return self::deny();
   if($kind==='HELD_OPERATION'&&(!is_string($request['operation_id'])||preg_match('/^[a-f0-9]{64}$/D',$request['operation_id'])!==1))return self::deny();
   if($kind==='ORDER_OBSERVATIONS'&&(!is_int($request['limit'])||$request['limit']<1||$request['limit']>20))return self::deny();
   $access=NativePanelAccessAdapter::access($scope,$actor,$context);if(is_wp_error($access))return self::deny();
   if($kind==='HELD_OPERATION'){
    $journal=(new NativeHeldJournal())->read($request['operation_id'],$scope->shopKey,$actor);if(is_wp_error($journal))return self::deny();$record=$journal['record'];$binding=$record['binding'];
    foreach(['scope_fingerprint'=>$scope->fingerprint(),'actor_id'=>$actor,'shop_key'=>$scope->shopKey,'shop_id'=>$scope->shopId,'integration_id'=>$scope->integrationId,'credential_reference'=>$scope->credential->integrationId,'provider_shop_id'=>$scope->providerShopId,'environment'=>$scope->environment,'authorization_revision'=>$scope->revision]as $key=>$value)if(($binding[$key]??null)!==$value)return self::deny();
    $observation=['state'=>$record['state'],'journal_revision'=>$record['revision'],'occurred_at'=>$record['occurred_at'],'event_hash'=>$journal['event_hash'],'fence_present'=>$record['fence_hash']!==null,'approval_verified'=>false,'native_product_version'=>null,'manual_review_required'=>true];
   }elseif($kind==='ORDER_OBSERVATIONS'){
    $observation=(new NativeOrderSourceJournal())->snapshot($scope,$actor,$request['limit']);if(is_wp_error($observation))return self::deny();
   }else $observation=['available_local_reads'=>['READINESS','HELD_OPERATION','ORDER_OBSERVATIONS'],'epoch_dependent_operations'=>'DENIED','authority_evidence'=>'NOT_QUALIFIED','observation_scope'=>'LOCAL_CORE_ONLY'];
   $last=NativePanelAccessAdapter::access($scope,$actor,$context);if(is_wp_error($last))return self::deny();
   // Fresh read receipts have sliding expiry; compare every immutable authority field.
   $volatile=['expires_at'=>true,'receipt_hash'=>true];if(NativePortContract::hash(array_diff_key($last,$volatile))!==NativePortContract::hash(array_diff_key($access,$volatile)))return self::deny();
   $r=['schema'=>'DF_NATIVE_READ_ONLY_INSPECTION_1','version'=>self::VERSION,'kind'=>$kind,'scope_fingerprint'=>$scope->fingerprint(),'actor_id'=>$actor,'access_receipt_hash'=>$access['receipt_hash'],'observation'=>$observation,'read_only'=>true,'mutation_authorized'=>false,'independent_checkpoint_verified'=>false,'recovery_epoch'=>null,'checkpoint_epoch'=>null,'network_request_permitted'=>false,'external_execution_authorized'=>false,'retry_permitted'=>false,'release_hold'=>true];$r['evidence_hash']=NativePortContract::hash($r);return $r;
  }catch(\Throwable){return self::deny();}
 }
 private static function deny():\WP_Error{return NativeFailure::error('local_inspection_unavailable');}
}
