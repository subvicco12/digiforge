<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Listings\Validator;
use DigiForge\PortalAccess\Owner;
use WP_Error;

/** Resolve explicit local product receipts; never enumerate protected records or remote uploads. */
final class PrintifyUploadOwnership
{
 public static function resolve(string $shop,int $integration,array $scope,array $references):array|WP_Error {
  if($shop!=='personalized_pod'||$integration<1||Owner::require($shop)!==true||!array_is_list($references)||!$references||count($references)>10||count(array_unique($references,SORT_REGULAR))!==count($references))return self::error();
  $current=PrintifyConnectionBoundary::context($shop,$integration,PrintifyProductPipeline::CAPABILITY);
  if(is_wp_error($current))return $current;
  // The caller may have a read/lifecycle capability; all underlying ownership tuple fields must agree.
  foreach($current as $k=>$value)if(!in_array($k,['capability','binding_hash'],true)&&($scope[$k]??null)!==$value)return self::error();
  if(PrintifyConnectionBoundary::runtime($integration,PrintifyProductPipeline::CAPABILITY,$current,$current['external_shop_reference'])!==true)return self::error();
  $pipeline=new PrintifyProductPipeline();$out=[];$ids=[];
  foreach($references as $hash){
   if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))return self::error();
   $receipt=$pipeline->receipt($hash);if(is_wp_error($receipt))return $receipt;
   if(($receipt['status']??null)!=='SUCCEEDED'||!in_array($receipt['operation']??null,['IMAGE_UPLOAD','IMAGE_READ'],true)||($receipt['integration_id']??null)!==$integration||($receipt['shop_id']??null)!==$current['external_shop_reference']||($receipt['connection_binding_hash']??null)!==$current['binding_hash']||($receipt['audit_required']??null)!==true)return self::error();
   $plan=$pipeline->plan($receipt['plan_hash']??'');if(is_wp_error($plan))return $plan;
   if(($plan['integration_id']??null)!==$integration||($plan['shop_id']??null)!==$current['external_shop_reference']||($plan['workflow']??null)!==($receipt['workflow']??null)||($plan['package']??null)!==($receipt['package']??null)||($plan['request']['operation']??null)!==$receipt['operation']||Validator::canonicalJson($plan['connection_scope']??[])!==Validator::canonicalJson($current))return self::error();
   $id=$receipt['data']['id']??null;if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$id)||isset($ids[$id]))return self::error();$ids[$id]=true;
   $out[]=['image_id'=>$id,'receipt_hash'=>$hash,'plan_hash'=>$receipt['plan_hash'],'workflow_hash'=>$receipt['workflow'],'package_hash'=>$receipt['package'],'connection_binding_hash'=>$current['binding_hash'],'source_sha256'=>hash('sha256',Validator::canonicalJson(['receipt_hash'=>$hash,'plan_hash'=>$receipt['plan_hash'],'image_id'=>$id]))];
  }
  return $out;
 }
 private static function error():WP_Error {return new WP_Error('printify_upload_ownership','Current exact owned upload source receipts required.',['status'=>409,'external_execution_authorized'=>false]);}
}
