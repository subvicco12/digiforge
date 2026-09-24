<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;
/** Validates and fingerprints the only Phase-1 Printify mutation requests. */
final class PrintifyMutationRequest{
 public static function prepare(array $permit,array $payload):array|WP_Error{
  $action=(string)($permit['action']??'');$integration=absint($payload['integration_id']??0);$shop=absint($payload['shop_id']??0);
  if($integration<1||$shop<1)return self::e('identity','Positive integration and shop identifiers are required.');
  if($action==='PROVIDER_ORDER_SUBMIT'){
   $body=is_array($payload['order']??null)?$payload['order']:[];
   if($body===[]||trim((string)($body['external_id']??''))===''||!is_array($body['line_items']??null)||$body['line_items']===[]||!is_array($body['address_to']??null))return self::e('order','Bound order payload, external_id, line_items and address_to are required.');
   $endpoint='/shops/'.$shop.'/orders.json';$external='';
  }elseif($action==='PROVIDER_PRODUCTION_AUTHORIZE'){
   $order=trim((string)($payload['order_id']??''));if($order===''||preg_match('/^[A-Za-z0-9_-]{1,160}$/',$order)!==1)return self::e('order_id','Valid provider order identity is required.');
   $body=[];$endpoint='/shops/'.$shop.'/orders/'.$order.'/send_to_production.json';$external=$order;
  }else return self::e('action','Unsupported Printify mutation action.');
  $material=['action'=>$action,'integration_id'=>$integration,'shop_id'=>$shop,'endpoint'=>$endpoint,'body'=>$body,'external_reference'=>$external];$json=wp_json_encode(self::canon($material));
  if(!is_string($json)||strlen($json)>262144)return self::e('payload','Printify mutation evidence is invalid or too large.');
  return ['state'=>'PRINTIFY_MUTATION_REQUEST_PREPARED','provider'=>'printify','api_version'=>'v1','v2_preference_preserved'=>true,'v1_required_for_operation'=>true,'action'=>$action,'integration_id'=>$integration,'shop_id'=>$shop,'method'=>'POST','endpoint'=>$endpoint,'body'=>$body,'existing_external_reference'=>$external,'request_fingerprint'=>hash('sha256',$json),'reconciliation_identity'=>['integration_id'=>$integration,'shop_id'=>$shop,'external_reference'=>$external!==''?$external:trim((string)($body['external_id']??''))],'network_request_permitted'=>false,'network_request_attempted'=>false,'external_execution_performed'=>false];
 }
 private static function canon(array $v):array{foreach($v as $k=>$x)if(is_array($x))$v[$k]=array_is_list($x)?array_map(fn($y)=>is_array($y)?self::canon($y):$y,$x):self::canon($x);if(!array_is_list($v))ksort($v);return $v;}
 private static function e(string $c,string $m):WP_Error{return new WP_Error('digiforge_printify_request_'.$c,$m,['status'=>409]);}
}
