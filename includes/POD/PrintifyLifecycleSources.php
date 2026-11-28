<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Listings\Validator;
use WP_Error;
/** Current source dependencies for uploads/support; never creates execution authority. */
final class PrintifyLifecycleSources {
 public static function extended(string $op):bool{return in_array($op,['UPLOADS_READ','IMAGE_ARCHIVE','SUPPORT_LIST','SUPPORT_DETAIL','SUPPORT_REPRINT','SUPPORT_REFUND','SUPPORT_ADDRESS_CHANGE'],true);}
 public static function observe(string $shop,int $integration,array $scope,string $op,array $input,string $readReceipt):array|WP_Error {
  if(in_array($op,['UPLOADS_READ','IMAGE_ARCHIVE'],true)){
   $refs=$input['image_receipts']??null;if(!is_array($refs))return self::error();$owned=PrintifyUploadOwnership::resolve($shop,$integration,$scope,$refs);if(is_wp_error($owned))return $owned;
   if($op==='IMAGE_ARCHIVE'&&!in_array($input['image_id']??null,array_column($owned,'image_id'),true))return self::error();
   return ['owned_images'=>$owned];
  }
  if(in_array($op,['SUPPORT_LIST','SUPPORT_DETAIL'],true))return [];
  if(!in_array($op,['SUPPORT_REPRINT','SUPPORT_REFUND','SUPPORT_ADDRESS_CHANGE'],true))return self::error();
  $r=(new PrintifyApiReadWorkflow())->receipt($readReceipt);$current=PrintifyConnectionBoundary::context($shop,$integration,PrintifyApiReadWorkflow::CAPABILITY);if(is_wp_error($r)||is_wp_error($current))return self::error();
  $observed=strtotime($r['observed_at']??'');if($observed===false||$observed<time()-300||$observed>time()||($r['status']??null)!=='SUCCEEDED'||($r['operation']??null)!=='ORDER_READ'||($r['shop_key']??null)!==$shop||($r['integration_id']??null)!==$integration||($r['provider_shop_id']??null)!==$scope['external_shop_reference']||($r['external_account_hash']??null)!==hash('sha256',$scope['external_account_reference'])||($r['connection_binding_hash']??null)!==$current['binding_hash'])return self::error();
  try{PrintifySupportContract::validateEligibility($op,$input,$r['data']);}catch(\Throwable){return self::error();}
  return ['receipt_hash'=>$readReceipt,'observed_at'=>$r['observed_at'],'order_sha256'=>hash('sha256',Validator::canonicalJson($r['data']))];
 }
 public static function project(array $plan,array $request,array $data,int $status):array {
  if(in_array($plan['operation'],['UPLOADS_READ','IMAGE_ARCHIVE'],true))return PrintifyUploadContract::project($request+['owned_images'=>$plan['reference']['owned_images']],$data,$status);
  return PrintifySupportContract::project($request,$data,$status);
 }
 private static function error():WP_Error{return new WP_Error('printify_lifecycle_source','Fresh exact owned supplier evidence is required.',['status'=>409,'retry_permitted'=>false]);}
}
