<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Pure, explicit GET scope. No wildcard, shop, product or order operations; shipping is explicitly scoped. */
final class PrintifyCatalogReadContract {
 public const CAPABILITY='printify.catalog.read';
 public static function normalize(array $g,int $now):array {
  self::exact($g,['capability','integration_id','account_id','business_id','store_id','product_program','expires_at','max_requests','requests']);
  if($g['capability']!==self::CAPABILITY)throw new \InvalidArgumentException('Explicit catalog read capability required.');
  foreach(['integration_id','account_id','max_requests']as $k)self::positive($g[$k]);
  if($g['max_requests']>10)throw new \InvalidArgumentException('At most ten catalog requests per grant.');
  foreach(['business_id','store_id']as $k)if(!is_string($g[$k])||preg_match('/^[a-z0-9_-]{1,100}$/D',$g[$k])!==1)throw new \InvalidArgumentException('Exact configured shop scope required.');
  if(!in_array($g['product_program'],['PERSONALIZED_POD','ORIGINAL_DESIGN_POD'],true))throw new \InvalidArgumentException('Configured product program required.');
  if(!is_string($g['expires_at'])||preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D',$g['expires_at'])!==1)throw new \InvalidArgumentException('Absolute ISO timestamp with timezone required.');
  $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',str_replace('Z','+00:00',$g['expires_at']));$errors=\DateTimeImmutable::getLastErrors();
  if(!$date||($errors!==false&&($errors['warning_count']||$errors['error_count']))||$date->getTimestamp()<=$now||$date->getTimestamp()>$now+3600)throw new \InvalidArgumentException('Expiry must be within one hour.');
  if(!is_array($g['requests'])||!array_is_list($g['requests'])||count($g['requests'])<1||count($g['requests'])>10)throw new \InvalidArgumentException('One to ten explicit requests required.');
  $seen=[];foreach($g['requests']as $r){self::endpoint($r);$key=json_encode($r,JSON_THROW_ON_ERROR);if(isset($seen[$key]))throw new \InvalidArgumentException('Duplicate scopes are unsupported.');$seen[$key]=true;}
  return $g;
 }
 public static function endpoint(array $r):string {
  $kind=$r['kind']??null;$keys=match($kind){'blueprint','providers'=>['kind','blueprint_id'],'variants'=>['kind','blueprint_id','provider_id'],'variant'=>['kind','blueprint_id','provider_id','variant_id'],'shipping'=>['kind','blueprint_id','provider_id'],'shipping_method'=>['kind','blueprint_id','provider_id','method'],default=>throw new \InvalidArgumentException('Unsupported catalog GET operation.')};self::exact($r,$keys);foreach($r as $k=>$v)if(!in_array($k,['kind','method'],true))self::positive($v);if($kind==='shipping_method')self::shippingMethod($r['method']);
  $base='/v1/catalog/blueprints/'.$r['blueprint_id'];return match($kind){'blueprint'=>$base.'.json','providers'=>$base.'/print_providers.json','shipping'=>'/v2/catalog/blueprints/'.$r['blueprint_id'].'/print_providers/'.$r['provider_id'].'/shipping.json','shipping_method'=>'/v2/catalog/blueprints/'.$r['blueprint_id'].'/print_providers/'.$r['provider_id'].'/shipping/'.$r['method'].'.json',default=>$base.'/print_providers/'.$r['provider_id'].'/variants.json'};
 }
 /** Validate endpoint-specific envelopes before accepting even draft evidence. */
 public static function select(array $data,array $r):array {
  self::endpoint($r);
  if((array_key_exists('next_page_url',$data)&&$data['next_page_url']!==null)||(isset($data['last_page'])&&$data['last_page']!==1))throw new \InvalidArgumentException('Catalog pagination is unsupported; partial evidence denied.');
  if(in_array($r['kind'],['shipping','shipping_method'],true)){self::shipping($data,$r);return $data;}
  if($r['kind']==='blueprint'){
   self::record($data);if($data['id']!==$r['blueprint_id'])throw new \InvalidArgumentException('Blueprint identity mismatch.');return $data;
  }
  if($r['kind']==='providers'){self::records($data);return $data;}
  $variants=$data['variants']??null;if(!is_array($variants))throw new \InvalidArgumentException('Variant response missing.');
  self::records($variants);if($r['kind']==='variants')return $data;
  $matches=array_values(array_filter($variants,static fn($v)=>$v['id']===$r['variant_id']));if(count($matches)!==1)throw new \InvalidArgumentException('Exact variant is absent or ambiguous.');return $matches[0];
 }
 /** V2 JSON:API envelopes are distinct from legacy v1 profiles. Always unverified evidence. */
 private static function shipping(array $data,array $request):void {
  if(array_diff(array_keys($data),['data','links'])||!isset($data['data'])||!is_array($data['data'])||!array_is_list($data['data'])||count($data['data'])>1000)throw new \InvalidArgumentException('Bounded v2 shipping data list required.');
  self::shippingNode($data,0);
  $seen=[];$ids=[];
  foreach($data['data']as $row){
   if(!is_array($row)||!is_string($row['id']??null)||!preg_match('/^[1-9][0-9]{0,9}$/D',$row['id'])||!is_array($row['attributes']??null))throw new \InvalidArgumentException('V2 shipping resource required.');
   $a=$row['attributes'];
   if($request['kind']==='shipping'){
    $name=$a['name']??null;self::shippingMethod($name);
    if(($row['type']??null)!=='shipping_method'||isset($seen[$name])||isset($ids[$row['id']]))throw new \InvalidArgumentException('Ambiguous shipping method.');
    $seen[$name]=true;$ids[$row['id']]=true;
   }else{
    $method=$request['method'];$country=$a['country']['code']??null;
    if(($a['shippingType']??null)!==$method||!is_string($country)||preg_match('/^(?:[A-Z]{2}|REST_OF_THE_WORLD)$/D',$country)!==1)throw new \InvalidArgumentException('Shipping method or country mismatch.');
    self::positive($a['variantId']??null);
    if($row['id']!==(string)$a['variantId']||($row['type']??null)!=='variant_shipping_'.$method.'_'.strtolower($country))throw new \InvalidArgumentException('Shipping variant/country association mismatch.');
    if(!is_string($a['shippingPlanId']??null)||preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$a['shippingPlanId'])!==1)throw new \InvalidArgumentException('Shipping plan evidence required.');
    $time=$a['handlingTime']??null;
    if(!is_array($time)||!is_int($time['from']??null)||!is_int($time['to']??null)||$time['from']<0||$time['to']<$time['from'])throw new \InvalidArgumentException('Invalid shipping time evidence.');
    $cost=$a['shippingCost']??null;
    if(!is_array($cost))throw new \InvalidArgumentException('Shipping cost evidence required.');
    foreach(['firstItem','additionalItems']as $key){$money=$cost[$key]??null;if(!is_array($money)||!is_int($money['amount']??null)||$money['amount']<0||!is_string($money['currency']??null)||preg_match('/^[A-Z]{3}$/D',$money['currency'])!==1)throw new \InvalidArgumentException('Invalid shipping money evidence.');}
    if($cost['firstItem']['currency']!==$cost['additionalItems']['currency'])throw new \InvalidArgumentException('Inconsistent shipping currencies.');
    $key=$a['variantId'].':'.$country;if(isset($seen[$key]))throw new \InvalidArgumentException('Ambiguous variant/country shipping evidence.');$seen[$key]=true;
   }
  }
  // Links are evidence only, never navigation or authorization. Reject foreign scope.
  if(array_key_exists('links',$data)){
   if(!is_array($data['links']))throw new \InvalidArgumentException('Invalid shipping links.');
   foreach($data['links']as $method=>$url){self::shippingMethod($method);$scope=['kind'=>'shipping_method','blueprint_id'=>$request['blueprint_id'],'provider_id'=>$request['provider_id'],'method'=>$method];if($url!=='https://api.printify.com'.self::endpoint($scope))throw new \InvalidArgumentException('Shipping link outside exact scope.');}
  }
 }
 private static function shippingMethod(mixed $method):void {
  if(!is_string($method)||!in_array($method,['standard','priority','express','economy'],true))throw new \InvalidArgumentException('Explicit supported shipping method required.');
 }
 private static function shippingNode(mixed $node,int $depth):void {
  if($depth>12)throw new \InvalidArgumentException('Shipping nesting exceeds limit.');
  if(is_array($node)){
   if(count($node)>1000)throw new \InvalidArgumentException('Shipping collection exceeds limit.');
   foreach($node as $key=>$value){if(is_string($key)&&(!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$key)))throw new \InvalidArgumentException('Unsafe shipping key.');self::shippingNode($value,$depth+1);}
   return;
  }
  if(is_string($node)){if(strlen($node)>2000||preg_match('/[\\x00-\\x08\\x0b\\x0c\\x0e-\\x1f]/',$node))throw new \InvalidArgumentException('Unsafe shipping value.');return;}
  if(is_int($node)||is_bool($node)||$node===null)return;
  if(is_float($node)&&is_finite($node))return;
  throw new \InvalidArgumentException('Unsupported shipping value.');
 }
 private static function records(array $records):void {
  if(!array_is_list($records)||count($records)>1000)throw new \InvalidArgumentException('Bounded catalog list required.');
  $seen=[];foreach($records as $record){if(!is_array($record))throw new \InvalidArgumentException('Catalog object required.');self::record($record);if(isset($seen[$record['id']]))throw new \InvalidArgumentException('Ambiguous catalog identity.');$seen[$record['id']]=true;}
 }
 private static function record(array $record):void {
  self::positive($record['id']??null);
  if(array_key_exists('title',$record)&&(!is_string($record['title'])||strlen($record['title'])>300||preg_match('/[<>\\x00-\\x1f]/',$record['title'])))throw new \InvalidArgumentException('Unsafe catalog label.');
  if(array_key_exists('is_available',$record)&&!is_bool($record['is_available']))throw new \InvalidArgumentException('Invalid availability evidence.');
 }
    public static function containsProtectedMaterial(array $data, string $token): bool
    {
        foreach ($data as $key=>$value) {
            if (is_string($key) && (str_contains($key,$token) || \DigiForge\Security\Logger::isCredentialKey($key))) return true;
            if (is_string($value) && str_contains($value,$token)) return true;
            if (is_array($value) && self::containsProtectedMaterial($value,$token)) return true;
        }
        return false;
    }

 private static function exact(array $v,array $keys):void {if(count($v)!==count($keys)||array_diff(array_keys($v),$keys))throw new \InvalidArgumentException('Unsupported or missing scope fields.');}
 private static function positive(mixed $v):void {if(!is_int($v)||$v<1||$v>2147483647)throw new \InvalidArgumentException('Positive bounded integer required.');}
}
