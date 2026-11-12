<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
use DigiForge\POD\PrintifyProductRequest;
use DigiForge\Connector\Printify\ShopBinding;
/** Pure request preparation. Evidence references are claims requiring independent core verification. */
final class ProductDraftContract
{
 public static function prepare(string $operation,ShopBinding $scope,array $input,array $evidence):array {
  if(!in_array($operation,['PRODUCT_CREATE','PRODUCT_UPDATE'],true))self::bad();
  $eKeys=['shop_key','integration_id','provider_shop_id','catalog_receipt_sha256','geometry_receipt_sha256','approval_receipt_sha256','authorization_revision','product_version','expected_product_version','blueprint_id','print_provider_id','allowed_variant_ids','owned_uploads','print_areas','current_product_id'];
  if(array_diff(array_keys($evidence),$eKeys))self::bad();
  foreach(['variants','print_areas']as $key)if(!is_array($input[$key]??null)||!array_is_list($input[$key])||$input[$key]===[])self::bad();
  foreach(['owned_uploads','print_areas']as $key)if(!is_array($evidence[$key]??null)||!array_is_list($evidence[$key])||$evidence[$key]===[])self::bad();
  foreach(['title','description','blueprint_id','print_provider_id','variants','print_areas'] as $key)if(!array_key_exists($key,$input))self::bad();
  foreach(['title','description'] as $key)if(!is_string($input[$key])||preg_match('/[<>\x00-\x1F\x7F]/',$input[$key]))self::bad();
  foreach(['shop_key'=>$scope->shopKey,'integration_id'=>$scope->integrationId,'provider_shop_id'=>$scope->providerShopId] as $key=>$value)if(($evidence[$key]??null)!==$value)self::bad();
  foreach(['catalog_receipt_sha256','geometry_receipt_sha256','approval_receipt_sha256'] as $key)self::digest($evidence[$key]??null);
  if(($evidence['authorization_revision']??null)!==$scope->revision)self::bad();
  $version=$evidence['product_version']??null;
  if(!is_int($version)||$version<0||($evidence['expected_product_version']??null)!==$version||($operation==='PRODUCT_CREATE'&&$version!==0)||($operation==='PRODUCT_UPDATE'&&($version<1||($evidence['current_product_id']??null)!==($input['product_id']??null))))self::bad();
  foreach(['blueprint_id','print_provider_id'] as $key)if(($evidence[$key]??null)!==$input[$key])self::bad();
  $allowed=$evidence['allowed_variant_ids']??null;if(!is_array($allowed)||!array_is_list($allowed)||count(array_unique($allowed,SORT_REGULAR))!==count($allowed))self::bad();
  $ids=[];foreach($input['variants'] as $variant){if(!is_array($variant)||!is_int($variant['price']??null)||$variant['price']<1||!in_array($variant['id']??null,$allowed,true))self::bad();$ids[]=$variant['id'];}
  $uploads=[];foreach($evidence['owned_uploads']??[] as $upload){if(!is_array($upload))self::bad();foreach(['shop_key'=>$scope->shopKey,'integration_id'=>$scope->integrationId,'provider_shop_id'=>$scope->providerShopId] as $key=>$value)if(($upload[$key]??null)!==$value)self::bad();foreach(['asset_sha256','upload_receipt_sha256'] as $key)self::digest($upload[$key]??null);foreach(['width_px','height_px'] as $key)if(!is_int($upload[$key]??null)||$upload[$key]<1)self::bad();$id=$upload['image_id']??null;if(!is_string($id)||isset($uploads[$id])||!in_array($id,$scope->ownedUploads,true))self::bad();$uploads[$id]=$upload;}
  $geometry=[];foreach($evidence['print_areas']??[] as $area){if(!is_array($area)||!is_string($area['position']??null)||isset($geometry[$area['position']]))self::bad();foreach(['width_px','height_px'] as $key)if(!is_int($area[$key]??null)||$area[$key]<1)self::bad();$zone=$area['safe_zone']??[];foreach(['x','y','width','height'] as $key)self::number($zone[$key]??null);if($zone['x']<0||$zone['y']<0||$zone['width']<=0||$zone['height']<=0||$zone['x']+$zone['width']>$area['width_px']||$zone['y']+$zone['height']>$area['height_px'])self::bad();$geometry[$area['position']]=$area;}
  $placed=[];foreach($input['print_areas'] as $area){foreach($area['variant_ids']??[] as $id){if(in_array($id,$placed,true))self::bad();$placed[]=$id;}foreach($area['placeholders']??[] as $placeholder){$g=$geometry[$placeholder['position']??'']??null;if($g===null)self::bad();foreach($placeholder['images']??[] as $image){$u=$uploads[$image['id']??'']??null;if($u===null)self::bad();foreach(['x','y','scale','angle'] as $key)self::number($image[$key]??null);if((float)$image['angle']!==0.0||$image['scale']<=0)self::bad();$width=$g['width_px']*$image['scale'];$height=$width*$u['height_px']/$u['width_px'];$left=$image['x']*$g['width_px']-$width/2;$top=$image['y']*$g['height_px']-$height/2;$z=$g['safe_zone'];if($left<0||$top<0||$left+$width>$g['width_px']||$top+$height>$g['height_px'])self::bad();$c=$u['content_bounds']??[];foreach(['x','y','width','height'] as $key)self::number($c[$key]??null);if($c['x']<0||$c['y']<0||$c['width']<=0||$c['height']<=0||$c['x']+$c['width']>$u['width_px']||$c['y']+$c['height']>$u['height_px'])self::bad();$ratio=$width/$u['width_px'];$cx=$left+$c['x']*$ratio;$cy=$top+$c['y']*$ratio;if($cx<$z['x']||$cy<$z['y']||$cx+$c['width']*$ratio>$z['x']+$z['width']||$cy+$c['height']*$ratio>$z['y']+$z['height'])self::bad();}}}
  sort($ids);sort($placed);if($ids!==$placed)self::bad();
  $wire=$input;if($operation==='PRODUCT_UPDATE')unset($wire['blueprint_id'],$wire['print_provider_id']);
  $core=PrintifyProductRequest::prepare($operation,$scope->providerShopId,$wire);
  $material=['core_request'=>$core,'scope_fingerprint'=>$scope->fingerprint(),'evidence'=>$evidence,'verification_state'=>'CORE_VERIFICATION_REQUIRED','network_request_permitted'=>false,'external_execution_authorized'=>false];
  return $material+['fingerprint'=>self::hash($material)];
 }
 public static function reconcile(array $request,array $response,int $httpStatus=200):array {
  $fingerprint=$request['fingerprint']??null;unset($request['fingerprint']);if(!is_string($fingerprint)||!hash_equals(self::hash($request),$fingerprint)||($request['external_execution_authorized']??null)!==false)self::bad();
  $core=$request['core_request']??[];if(!PrintifyProductRequest::intact($core)||!in_array($core['operation'],['PRODUCT_CREATE','PRODUCT_UPDATE'],true)||$httpStatus<200||$httpStatus>299)self::bad();
  $body=$core['body'];$e=$request['evidence'];if(self::shopId($response['shop_id']??null)!==$e['provider_shop_id'])self::bad();foreach(['blueprint_id'=>$e['blueprint_id'],'print_provider_id'=>$e['print_provider_id']] as $key=>$value)if(($response[$key]??null)!==$value)self::bad();
  $id=$response['id']??null;if(!is_string($id)||preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$id)!==1||($core['operation']==='PRODUCT_UPDATE'&&$id!==$e['current_product_id']))self::bad();
  foreach(['published_at','published','publishing'] as $key)if(isset($response[$key])&&$response[$key]!==false)self::bad();
  if(($response['is_locked']??null)!==false||!is_bool($response['visible']??null)||in_array($response['status']??null,['published','publishing'],true))self::bad();
  if(!is_array($response['variants']??null)||!array_is_list($response['variants']))self::bad();$ids=[];foreach($response['variants'] as $variant){if(!is_array($variant)||!is_int($variant['id']??null)||in_array($variant['id'],$ids,true))self::bad();$ids[]=$variant['id'];}$expected=array_column($body['variants'],'id');sort($ids);sort($expected);if($ids!==$expected)self::bad();
  $result=['product_id'=>$id,'shop_id'=>$e['provider_shop_id'],'blueprint_id'=>$e['blueprint_id'],'print_provider_id'=>$e['print_provider_id'],'variant_ids'=>$ids,'state'=>'PRODUCT_METADATA_OBSERVED_UNCERTIFIED','publication_verified'=>false,'request_fingerprint'=>$fingerprint,'network_request_permitted'=>false,'external_execution_authorized'=>false];return $result+['response_fingerprint'=>self::hash($result)];
 }
 private static function shopId(mixed $id):string{if(is_int($id)&&$id>0)return (string)$id;if(is_string($id)&&preg_match('/^[1-9][0-9]{0,18}$/D',$id)===1)return $id;self::bad();}
 private static function number(mixed $v):void{if((!is_int($v)&&!is_float($v))||!is_finite((float)$v))self::bad();}
 private static function digest(mixed $v):void{if(!is_string($v)||preg_match('/^[a-f0-9]{64}$/D',$v)!==1)self::bad();}
 private static function hash(array $v):string{return hash('sha256',json_encode(self::canonical($v),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));}
 private static function canonical(array $v):array{foreach($v as &$item)if(is_array($item))$item=self::canonical($item);unset($item);if(!array_is_list($v))ksort($v);return $v;}
 private static function bad():never{throw new \InvalidArgumentException('Exact scoped draft evidence, complete variants and bounded approved geometry required.');}
}
