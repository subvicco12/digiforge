<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
/** Pure observations of synthetic/provider data. Never creates a trusted receipt or dispatch authority. */
final class ProviderMutationResponse {
 private static function jsonValues(array $body,int $depth):void {
  if($depth>30)throw new \InvalidArgumentException();
  foreach($body as $value){if(is_array($value))self::jsonValues($value,$depth+1);elseif(!is_null($value)&&!is_string($value)&&!is_bool($value)&&!is_int($value)&&!(is_float($value)&&is_finite($value)))throw new \InvalidArgumentException();}
 }
 public static function observe(MutationIntent $intent,array $body,int $status):array|\WP_Error {
  try{
   if($status<0||$status>599||($status>0&&$status<100))throw new \InvalidArgumentException();
   $base=['schema'=>'DF_PRINTIFY_MUTATION_OBSERVATION_1','operation_id'=>$intent->operationId,'payload_fingerprint'=>$intent->payloadFingerprint,'intent_fingerprint'=>$intent->intentFingerprint,'scope_fingerprint'=>$intent->scope->fingerprint(),'authorization_revision'=>$intent->context['authorization_revision'],'product_version'=>$intent->context['product_version'],'http_status'=>$status,'trusted_receipt'=>false,'retry_permitted'=>false,'network_request_permitted'=>false,'external_execution_authorized'=>false];
   // An HTTP failure cannot prove that a consequential write did not occur. Never echo its body.
   if($status!==200)return $base+['kind'=>'UNKNOWN','body'=>[],'metadata'=>['state'=>'UNKNOWN']];
   self::jsonValues($body,0);
   if(strlen(json_encode($body,JSON_THROW_ON_ERROR,32))>1048576)throw new \InvalidArgumentException();
   if(array_key_exists('shop_id',$body)){
    $shop=$body['shop_id'];if(is_int($shop)&&$shop>0)$shop=(string)$shop;
    if(!is_string($shop)||!preg_match('/^[1-9][0-9]{0,18}$/D',$shop)||$shop!==$intent->scope->providerShopId)throw new \InvalidArgumentException();
   }
   $prepared=$intent->prepared();
   if($intent->operation==='PRODUCT_PUBLISH'){$metadata=PublicationContract::reconcile($prepared,$body,$status);$projected=[];}
   elseif($intent->operation==='IMAGE_UPLOAD'){
    $metadata=UploadContract::reconcile($prepared,$body,$status);
    foreach(['size'=>'byte_count','mime_type'=>'mime_type']as $provider=>$asset)if(array_key_exists($provider,$body)&&$body[$provider]!==$prepared['asset'][$asset])throw new \InvalidArgumentException();
    $projected=array_intersect_key($body,array_flip(['id','file_name','width','height']));
   }else{
    // Validate original evidence before projection: published/locked/wrong-shop contradictions may not disappear.
    $metadata=ProductDraftContract::reconcile($prepared,$body,$status);
    $projected=array_intersect_key($body,array_flip(['id','shop_id','blueprint_id','print_provider_id','variants','visible','is_locked']));
    $projected['variants']=array_map(static fn(array $v):array=>['id'=>$v['id']],$body['variants']);
   }
   return $base+['kind'=>'RESPONSE','body'=>$projected,'metadata'=>$metadata];
  }catch(\Throwable){return new \WP_Error('printify_mutation_observation_denied','Exact scoped provider metadata required; write outcome remains uncertain.',['outcome'=>'UNKNOWN','retry_permitted'=>false,'network_request_permitted'=>false,'external_execution_authorized'=>false]);}
 }
}
