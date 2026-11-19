<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Listings\Validator;
use DigiForge\Connector\Printify\ExternalOperationIntent;
use WP_Error;
/** Native joins to existing catalog grants and owned image receipts. No transport or independent authority. */
final class PrintifyNativeReadSources {
 public static function extended(string $op):bool{return in_array($op,['BLUEPRINTS_READ','BLUEPRINT_READ','VARIANTS_READ','SHIPPING_READ','UPLOADS_READ','UPLOAD_READ'],true);}
 public static function catalog(string $op):bool{return in_array($op,['BLUEPRINTS_READ','BLUEPRINT_READ','VARIANTS_READ','SHIPPING_READ'],true);}
 public static function describe(ExternalOperationIntent $i,string $grant,int $index,array $scope):array|WP_Error {
  try{
   $r=PrintifyConnectorReadContract::prepare($i->operation,$scope['external_shop_reference'],$i->input);
   if(self::catalog($i->operation)){
    if($i->sourceReferences!==[])return self::error();$source=(new PrintifyCatalogReadRepository())->nativeGrant($grant,$index,$i->shopKey,$i->integrationId,$r['endpoint']);if(is_wp_error($source))return $source;
    $kind=match($i->operation){'BLUEPRINTS_READ'=>'blueprints','BLUEPRINT_READ'=>'blueprint','VARIANTS_READ'=>'variants','SHIPPING_READ'=>isset($i->input['shipping_method'])?'shipping_method':'shipping'};if(($source['request']['kind']??null)!==$kind)return self::error();
    return ['type'=>'catalog','grant_sha256'=>$grant,'request_index'=>$index,'source'=>$source,'source_references'=>[]];
   }
   if(!in_array($i->operation,['UPLOAD_READ','UPLOADS_READ'],true)||$grant!==''||$index!==0)return self::error();
   $owned=PrintifyUploadOwnership::resolve($i->shopKey,$i->integrationId,$scope,$i->sourceReferences);if(is_wp_error($owned))return $owned;
   if($i->operation==='UPLOAD_READ'&&!in_array($i->input['upload_id'],array_column($owned,'image_id'),true))return self::error();
   return ['type'=>'upload','source_references'=>$i->sourceReferences,'source'=>$owned];
  }catch(\Throwable){return self::error();}
 }
 public static function current(array $p):true|WP_Error {
  try{$s=$p['native_sources'];$i=new ExternalOperationIntent($p['created_by'],$p['shop_key'],$p['integration_id'],$p['request']['operation'],$p['input'],str_repeat('0',32),$s['source_references']);$again=self::describe($i,$s['grant_sha256']??'', $s['request_index']??0,$p['connection_scope']);return !is_wp_error($again)&&Validator::canonicalJson($again)===Validator::canonicalJson($s)?true:self::error();}catch(\Throwable){return self::error();}
 }
 public static function project(array $p,array $body):array {
  $op=$p['request']['operation'];$s=$p['native_sources'];
  if(self::catalog($op)){
   $selected=PrintifyCatalogReadContract::select($body,$s['source']['request']);
   // Exact validated catalog evidence only, never supplier certification or caller-provided geometry.
   return self::projection($p,$selected,[]);
  }
  $input=$p['input'];$list=PrintifyUploadContract::prepare('UPLOADS_READ',$p['provider_shop_id'],$op==='UPLOADS_READ'?$input:[]);
  if($op==='UPLOAD_READ'){
   if(($body['id']??null)!==$input['upload_id'])throw new \InvalidArgumentException('Owned image identity mismatch.');
   $page=PrintifyUploadContract::project($list+['owned_images'=>$s['source']],['data'=>[$body],'current_page'=>1,'per_page'=>10,'last_page'=>1]);
   if(count($page['data'])!==1)throw new \InvalidArgumentException('Owned image missing.');
   return self::projection($p,$page['data'][0],array_column($s['source'],'image_id'));
  }
  $page=PrintifyUploadContract::project($list+['owned_images'=>$s['source']],$body);return self::projection($p,$page,array_column($s['source'],'image_id'));
 }
 private static function projection(array $p,array $body,array $owned):array {
  $scope=(new ScopedProviderConnection())->verified($p['shop_key'],$p['integration_id'],PrintifyApiReadWorkflow::CAPABILITY);if(is_wp_error($scope))throw new \RuntimeException('Current projection scope unavailable.');
  $binding=new \DigiForge\Connector\Printify\ShopBinding(['personalized_pod'=>2,'standard_pod'=>3][$p['shop_key']],$p['shop_key'],$p['integration_id'],$p['provider_shop_id'],$scope['environment'],$scope['review_revision'],new \DigiForge\Connector\Printify\CredentialReference($p['integration_id']),$scope['binding_hash'],$scope['expires_at'],$owned);
  $intent=new ExternalOperationIntent($p['created_by'],$p['shop_key'],$p['integration_id'],$p['request']['operation'],$p['input'],str_repeat('0',32),$p['native_sources']['source_references']);
  return \DigiForge\Connector\Printify\ReadProjection::project($intent,$binding,$body);
 }
 private static function error():WP_Error{return new WP_Error('printify_native_read_source','Current exact catalog grant or owned upload receipts required.',['retry_permitted'=>false,'external_execution_authorized'=>false]);}
}
