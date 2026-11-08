<?php
declare(strict_types=1);
namespace DigiForge\POD;
/** Strict, private-data-minimizing lifecycle responses. */
final class PrintifyLifecycleEvidence
{
 public static function project(array $request,array $data):array {
  $op=$request['operation'];
  if($op==='SHIPPING_QUOTE'){
   if(array_diff(array_keys($data),['standard','express','priority','printify_express','economy']))throw new \InvalidArgumentException('Unknown shipping quote fields.');
   foreach($data as $cost)if(!is_int($cost)||$cost<0||$cost>100000000)throw new \InvalidArgumentException('Unverified shipping cost.');
   if(isset($data['express'],$data['priority'])&&$data['express']!==$data['priority'])throw new \InvalidArgumentException('Ambiguous transitional shipping aliases.');
   return ['rates_minor'=>['standard'=>$data['standard']??null,'priority'=>$data['priority']??$data['express']??null,'printify_express'=>$data['printify_express']??null,'economy'=>$data['economy']??null],'currency'=>null,'quote_is_guarantee'=>false,'production_certified'=>false];
  }
  if($op==='ORDER_CANCEL'){
   preg_match('#/orders/([^/]+)/cancel.json$#D',$request['endpoint'],$m);
   $out=PrintifyOrderEvidence::project($data,$m[1]??'');if($out['status']!=='canceled')throw new \InvalidArgumentException('Cancellation not confirmed.');return $out;
  }
  if($op==='WEBHOOK_READ'){
   if(!array_is_list($data)||count($data)>100)throw new \InvalidArgumentException('Unbounded webhook list.');$out=[];$seen=[];
   foreach($data as $r){$row=self::subscription($request,$r);if(isset($seen[$row['id']]))throw new \InvalidArgumentException('Duplicate webhook identity.');$seen[$row['id']]=true;$out[]=$row;}return ['subscriptions'=>$out];
  }
  if($op==='WEBHOOK_DELETE'){
   if(!is_string($data['id']??null)||!str_contains($request['endpoint'],'/webhooks/'.$data['id'].'.json?'))throw new \InvalidArgumentException('Webhook deletion identity mismatch.');return ['id'=>$data['id'],'deletion_acknowledged'=>true];
  }
  return self::subscription($request,$data);
 }
 private static function subscription(array $request,array $data):array {
  if(!is_string($data['id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$data['id'])||!is_string($data['topic']??null)||strlen($data['topic'])>100||!is_string($data['url']??null)||strlen($data['url'])>2048||!filter_var($data['url'],FILTER_VALIDATE_URL)||parse_url($data['url'],PHP_URL_SCHEME)!=='https'||parse_url($data['url'],PHP_URL_USER)!==null||parse_url($data['url'],PHP_URL_PASS)!==null)throw new \InvalidArgumentException('Invalid webhook evidence.');
  preg_match('#^/v1/shops/([0-9]+)/#',$request['endpoint'],$m);if((string)($data['shop_id']??'')!==($m[1]??''))throw new \InvalidArgumentException('Webhook shop mismatch.');
  if(isset($request['input']['webhook_id'])&&$data['id']!==$request['input']['webhook_id'])throw new \InvalidArgumentException('Webhook identity mismatch.');
  foreach($request['body'] as $k=>$v)if(($data[$k]??null)!==$v)throw new \InvalidArgumentException('Webhook readback mismatch.');
  return ['id'=>$data['id'],'topic'=>$data['topic'],'shop_id'=>(string)$data['shop_id'],'url'=>$data['url'],'authenticity_live_verified'=>false];
 }
}
