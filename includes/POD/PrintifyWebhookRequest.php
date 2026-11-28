<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Offline lifecycle requests. Signing material is resolved only inside a transient callback. */
final class PrintifyWebhookRequest
{
 public static function binding(string $binding):array {
  if(!preg_match('/^[a-f0-9]{32}$/D',$binding)||!defined('DIGIFORGE_PRINTIFY_WEBHOOK_CONFIG'))throw new \InvalidArgumentException('Webhook binding unavailable.');
  $path=constant('DIGIFORGE_PRINTIFY_WEBHOOK_CONFIG');
  if(!is_string($path)||$path===''||$path[0]!=='/'||is_link($path)||realpath($path)!==$path||!is_file($path)||(fileperms($path)&0177)!==0||filesize($path)>65536)throw new \InvalidArgumentException('Private webhook configuration unavailable.');
  $root=defined('ABSPATH')?realpath(ABSPATH):false;
  if($root===false||str_starts_with($path,rtrim($root,'/').'/'))throw new \InvalidArgumentException('Private webhook configuration location invalid.');
  $all=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);$entry=$all[$binding]??null;
  if(!is_array($entry)||($entry['site']??null)!==home_url()||!in_array($entry['shop_key']??null,['personalized_pod','standard_pod'],true)||!is_int($entry['integration_id']??null)||$entry['integration_id']<1||!preg_match('/^[1-9][0-9]{0,18}$/D',(string)($entry['provider_shop_id']??''))||!is_string($entry['current_secret']??null)||strlen($entry['current_secret'])<32)throw new \InvalidArgumentException('Webhook binding invalid.');
  foreach($all as $key=>$other)if($key!==$binding&&is_array($other))foreach(['current_secret','previous_secret'] as $field)if(isset($other[$field])&&(hash_equals($entry['current_secret'],(string)$other[$field])||(isset($entry['previous_secret'])&&hash_equals((string)$entry['previous_secret'],(string)$other[$field]))))throw new \InvalidArgumentException('Signing material must be unique per binding.');
  if(isset($entry['previous_secret'])&&(!is_string($entry['previous_secret'])||strlen($entry['previous_secret'])<32||!is_int($entry['rotation_started_at']??null)||!is_int($entry['previous_valid_until']??null)||$entry['rotation_started_at']>time()||$entry['previous_valid_until']<=$entry['rotation_started_at']||$entry['previous_valid_until']-$entry['rotation_started_at']>86400))throw new \InvalidArgumentException('Invalid rotation window.');
  return $entry;
 }
 public static function withSecret(string $binding,string $shop_key,int $integration_id,callable $callback):mixed {
  $entry=self::binding($binding);if($entry['shop_key']!==$shop_key||$entry['integration_id']!==$integration_id)throw new \InvalidArgumentException('Webhook signing scope mismatch.');
  return $callback($entry['current_secret']);
 }
 public static function prepare(string $operation,string $providerShop,array $input):array {
  if(!preg_match('/^[1-9][0-9]{0,18}$/D',$providerShop)||!in_array($operation,['WEBHOOK_CREATE','WEBHOOK_UPDATE','WEBHOOK_DELETE','WEBHOOK_READ'],true))throw new \InvalidArgumentException('Invalid webhook operation.');
  if(array_diff(array_keys($input),['binding','webhook_id','topic','url','host']))throw new \InvalidArgumentException('Unsupported webhook input.');
  $endpoint='/v1/shops/'.$providerShop.'/webhooks';$body=[];$method='GET';$metadata=[];
  if(in_array($operation,['WEBHOOK_UPDATE','WEBHOOK_DELETE'],true)){$id=$input['webhook_id']??'';if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$id))throw new \InvalidArgumentException('Webhook identity required.');$endpoint.='/'.$id;}
  $endpoint.='.json';
  if($operation!=='WEBHOOK_READ'){
   $binding=(string)($input['binding']??'');$entry=self::binding($binding);if((string)$entry['provider_shop_id']!==$providerShop)throw new \InvalidArgumentException('Webhook shop mismatch.');
   $url=rest_url('digiforge/v1/printify/webhooks/ingress/'.$binding);$host=parse_url($url,PHP_URL_HOST);
   if(parse_url($url,PHP_URL_SCHEME)!=='https'||!is_string($host))throw new \InvalidArgumentException('HTTPS callback required.');
   if($operation==='WEBHOOK_DELETE') {if(($input['host']??null)!==$host)throw new \InvalidArgumentException('Expected callback host required.');$method='DELETE';$endpoint.='?host='.rawurlencode($host);}
   else {if(isset($input['url'])&&$input['url']!==$url)throw new \InvalidArgumentException('Exact callback URL required.');$topic=$input['topic']??'';if(!is_string($topic)||!in_array($topic,['shop:disconnected','product:created','product:updated','product:deleted','product:publish:started','order:created','order:updated','order:line-item:canceled','order:shipment:created','order:shipment:delivered','order:sent-to-production','personalization-preview-task:processed','support-request:updated'],true))throw new \InvalidArgumentException('Supported topic required.');$body=$operation==='WEBHOOK_CREATE'?['topic'=>$topic,'url'=>$url]:['url'=>$url];$method=$operation==='WEBHOOK_CREATE'?'POST':'PUT';if($operation==='WEBHOOK_CREATE')$metadata['secret_fingerprint']=hash('sha256',$entry['current_secret']);}
  }
  return ['operation'=>$operation,'method'=>$method,'endpoint'=>$endpoint,'body'=>$body,'input'=>$input,'network_request_permitted'=>false]+$metadata;
 }
}
