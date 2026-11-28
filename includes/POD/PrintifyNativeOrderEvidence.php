<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;
/** Resolves opaque native order values only from audited, current approved product receipts. */
final class PrintifyNativeOrderEvidence
{
 public static function configuration(mixed $value):void {
  if(!is_array($value)||count($value)!==2||array_diff(array_keys($value),['personalisation_strategy','personalisation_instructions'])||($value['personalisation_strategy']??null)!=='pstudio_pre_order_headless'||!is_string($value['personalisation_instructions']??null)||trim($value['personalisation_instructions'])===''||strlen($value['personalisation_instructions'])>2000||preg_match('/[\x00-\x1F\x7F]/',$value['personalisation_instructions']))throw new \InvalidArgumentException('Exact supported provider configuration required.');
 }
 /** Stable ownership tuple: capability-specific binding hashes deliberately differ. */
 public static function validateResolved(array $line,array $receipt,array $plan,int $integration,int $shop,array $scope):void {
  self::configuration($line['personalisation']??null);self::configuration($receipt['data']??null);
  if(($receipt['status']??null)!=='SUCCEEDED'||($receipt['operation']??null)!=='PERSONALIZATION_CONFIGURE'||($receipt['network_request_attempted']??null)!==true||($receipt['audit_required']??null)!==true||($receipt['integration_id']??null)!==$integration||($plan['integration_id']??null)!==$integration||($receipt['shop_id']??null)!==(string)$shop||($plan['shop_id']??null)!==(string)$shop||($plan['request']['operation']??null)!=='PERSONALIZATION_CONFIGURE'||($plan['request']['body']['variant_id']??null)!==($line['variant_id']??null)||($plan['selection']['variant_id']??null)!==($line['variant_id']??null)||($plan['request']['endpoint']??null)!=='/v1/shops/'.$shop.'/products/'.($line['product_id']??'').'/personalization.json'||($receipt['workflow']??null)!==($plan['workflow']??null)||($receipt['package']??null)!==($plan['package']??null)||($receipt['connection_binding_hash']??null)!==($plan['connection_scope']['binding_hash']??null))throw new \InvalidArgumentException('Native receipt scope mismatch.');
  foreach(['installation_sha256','environment','business_id','store_id','program_id','shop_key','provider','external_account_reference','external_shop_reference','integration_id'] as $key){if(!isset($scope[$key],$plan['connection_scope'][$key])||$scope[$key]!==$plan['connection_scope'][$key])throw new \InvalidArgumentException('Native account mismatch.');}
  if(($scope['provider']??null)!=='printify'||($scope['shop_key']??null)!=='personalized_pod'||($scope['external_shop_reference']??null)!==(string)$shop)throw new \InvalidArgumentException('Native shop mismatch.');
  foreach(['personalisation_strategy','personalisation_instructions'] as $key)if($line['personalisation'][$key]!==$receipt['data'][$key])throw new \InvalidArgumentException('Native values must match provider receipt.');
 }
 /** Metadata is indexed by request line; it is never included in the provider body. */
 public static function resolve(array $body,mixed $references,int $integration,int $shop,mixed $scope):array|WP_Error {
  try{
   if(!is_array($references)||!array_is_list($references))throw new \InvalidArgumentException('Native reference list required.');
   $native=[];foreach($body['line_items']??[] as $index=>$line)if(is_array($line)&&array_key_exists('personalisation',$line))$native[$index]=$line;
   if($native===[]){if($references!==[])throw new \InvalidArgumentException('Orphan native references.');return [];}
   if(!is_array($scope)||count($native)!==count($references))throw new \InvalidArgumentException('Every native line requires one reference.');
   $seen=[];$hashes=[];$out=[];$pipeline=new PrintifyProductPipeline();
   foreach($references as $ref){
    if(!is_array($ref)||count($ref)!==2||array_diff(array_keys($ref),['line_index','configuration_receipt'])||!is_int($ref['line_index']??null)||!isset($native[$ref['line_index']])||isset($seen[$ref['line_index']])||!is_string($ref['configuration_receipt']??null)||!preg_match('/^[a-f0-9]{64}$/D',$ref['configuration_receipt'])||isset($hashes[$ref['configuration_receipt']]))throw new \InvalidArgumentException('Malformed, duplicate or orphan native reference.');
    $hash=$ref['configuration_receipt'];$r=$pipeline->receipt($hash);if(is_wp_error($r))return $r;$p=$pipeline->plan($r['plan_hash']);if(is_wp_error($p))return $p;
    self::validateResolved($native[$ref['line_index']],$r,$p,$integration,$shop,$scope);
    $current=PrintifyConnectionBoundary::authorize($integration,PrintifyProductPipeline::CAPABILITY,$p['connection_scope'],(string)$shop);if(is_wp_error($current))return $current;
    $seen[$ref['line_index']]=true;$hashes[$hash]=true;$out[]=$ref+['plan_hash'=>$r['plan_hash'],'package_hash'=>$p['package']];
   }
   usort($out,fn($a,$b)=>$a['line_index']<=>$b['line_index']);return $out;
  }catch(\Throwable){return new WP_Error('digiforge_printify_native_order_evidence','Current audited native configuration references must exactly match every native line.',['status'=>409]);}
 }
}
