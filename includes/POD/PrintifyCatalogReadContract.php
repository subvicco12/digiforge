<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Pure, explicit GET scope. No wildcard, shipping, shop, product or order operations. */
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
  $kind=$r['kind']??null;$keys=match($kind){'blueprint','providers'=>['kind','blueprint_id'],'variants'=>['kind','blueprint_id','provider_id'],'variant'=>['kind','blueprint_id','provider_id','variant_id'],default=>throw new \InvalidArgumentException('Unsupported catalog GET operation.')};self::exact($r,$keys);foreach($r as $k=>$v)if($k!=='kind')self::positive($v);
  $base='/v1/catalog/blueprints/'.$r['blueprint_id'];return match($kind){'blueprint'=>$base.'.json','providers'=>$base.'/print_providers.json',default=>$base.'/print_providers/'.$r['provider_id'].'/variants.json'};
 }
 /** Validate endpoint-specific envelopes before accepting even draft evidence. */
 public static function select(array $data,array $r):array {
  self::endpoint($r);
  if($r['kind']==='blueprint'){
   self::record($data);if($data['id']!==$r['blueprint_id'])throw new \InvalidArgumentException('Blueprint identity mismatch.');return $data;
  }
  if($r['kind']==='providers'){self::records($data);return $data;}
  $variants=$data['variants']??null;if(!is_array($variants))throw new \InvalidArgumentException('Variant response missing.');
  self::records($variants);if($r['kind']==='variants')return $data;
  $matches=array_values(array_filter($variants,static fn($v)=>$v['id']===$r['variant_id']));if(count($matches)!==1)throw new \InvalidArgumentException('Exact variant is absent or ambiguous.');return $matches[0];
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
