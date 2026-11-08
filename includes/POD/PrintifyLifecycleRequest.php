<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Listings\Validator;
/** Descriptions only. Exact owner-reviewed plans separately authorize dispatch. */
final class PrintifyLifecycleRequest
{
 public static function prepare(string $operation,string $shop,array $input):array {
  if(!preg_match('/^[1-9][0-9]{0,18}$/D',$shop))throw new \InvalidArgumentException('Provider shop required.');
  if(str_starts_with($operation,'WEBHOOK_')){
   $r=PrintifyWebhookRequest::prepare($operation,$shop,$input);$material=['operation'=>$operation,'method'=>$r['method'],'endpoint'=>$r['endpoint'],'body'=>$r['body'],'required_scope'=>$r['method']==='GET'?'webhooks.read':'webhooks.write','input'=>$r['input'],'secret_fingerprint'=>$r['secret_fingerprint']??null];
  }elseif($operation==='SHIPPING_QUOTE'){
   if(array_keys($input)!==['order']||!is_array($input['order']))throw new \InvalidArgumentException('Exact quote order required.');
   PrintifyOrderEvidence::validate($input['order']);
   $material=['operation'=>$operation,'method'=>'POST','endpoint'=>'/v1/shops/'.$shop.'/orders/shipping.json','body'=>['line_items'=>$input['order']['line_items'],'address_to'=>$input['order']['address_to']],'required_scope'=>'orders.write'];
  }elseif($operation==='LINE_ITEM_CANCEL'){
   if(count($input)!==2||array_diff(array_keys($input),['order_id','line_item_id']))throw new \InvalidArgumentException('Exact order and line required.');
   foreach(['order_id','line_item_id'] as $key)if(!is_string($input[$key]??null)||!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$input[$key]))throw new \InvalidArgumentException('Exact order and line required.');
   $material=['operation'=>$operation,'method'=>'POST','endpoint'=>'/v1/shops/'.$shop.'/orders/'.$input['order_id'].'/line_items/'.$input['line_item_id'].'/cancel.json','body'=>[],'required_scope'=>'orders.write'];
  }elseif($operation==='ORDER_CANCEL'){
   if(array_keys($input)!==['order_id']||!is_string($input['order_id'])||!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$input['order_id']))throw new \InvalidArgumentException('Exact order required.');
   $material=['operation'=>$operation,'method'=>'POST','endpoint'=>'/v1/shops/'.$shop.'/orders/'.$input['order_id'].'/cancel.json','body'=>[],'required_scope'=>'orders.write'];
  }else throw new \InvalidArgumentException('Unsupported lifecycle operation.');
  $fingerprintMaterial=$material;unset($fingerprintMaterial['input']);
  return $material+['fingerprint'=>hash('sha256',Validator::canonicalJson($fingerprintMaterial)),'external_execution_authorized'=>false];
 }
}
