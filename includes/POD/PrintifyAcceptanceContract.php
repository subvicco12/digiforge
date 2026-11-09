<?php
declare(strict_types=1);
namespace DigiForge\POD;
/** Fixed provider GET grammar and schema-only evidence. No values or arbitrary paths escape. */
final class PrintifyAcceptanceContract {
 public const MAX_REQUESTS=10, PER_MINUTE=3, MAX_PAGE=3, TTL=300;
 public static function inspect(array $r,string $shop):array {
  if(array_diff(array_keys($r),['operation','method','endpoint','body','required_scope','provider_shop_id','input','fingerprint','external_execution_authorized','scope','scope_identity','secret_fingerprint']))throw new \InvalidArgumentException('Unknown request fields');
  if(!preg_match('/^[1-9][0-9]{0,18}$/D',$shop)||($r['method']??null)!=='GET'||($r['body']??[])!==[]||isset($r['headers'])||isset($r['url']))throw new \InvalidArgumentException('GET contract required');
  $id='[A-Za-z0-9_-]{1,160}';$n='[1-9][0-9]{0,9}';$root='/v1/shops/'.preg_quote($shop,'#');
  $rules=[
   'SHOPS_READ'=>'/v1/shops.json','PROVIDERS_READ'=>'/v1/catalog/print_providers.json','PROVIDER_READ'=>'/v1/catalog/print_providers/'.$n.'.json',
   'BLUEPRINTS'=>'/v1/catalog/blueprints.json','BLUEPRINT'=>'/v1/catalog/blueprints/'.$n.'.json','BLUEPRINT_PROVIDERS'=>'/v1/catalog/blueprints/'.$n.'/print_providers.json','BLUEPRINT_PROVIDER'=>'/v1/catalog/blueprints/'.$n.'/print_providers/'.$n.'.json','VARIANTS'=>'/v1/catalog/blueprints/'.$n.'/print_providers/'.$n.'/variants.json','SIZE_GUIDE'=>'/v1/catalog/blueprints/'.$n.'/size_guide.json',
   'SHIPPING_METHODS'=>'/v2/catalog/blueprints/'.$n.'/print_providers/'.$n.'/shipping.json','REGIONAL_SHIPPING'=>'/v2/catalog/blueprints/'.$n.'/print_providers/'.$n.'/shipping/(?:standard|priority|express|economy).json',
   'PRODUCT_READ'=>$root.'/products/'.$id.'.json','PRODUCT_GPSR_READ'=>$root.'/products/'.$id.'/gpsr.json','PERSONALIZATION_OPTIONS'=>$root.'/products/'.$id.'/personalization_options.json','PREVIEW_READ'=>$root.'/products/'.$id.'/personalization_previews/'.$id.'.json',
   'IMAGE_READ'=>'/v1/uploads/'.$id.'.json','UPLOADS_READ'=>'/v1/uploads.json\?page=[1-3]&limit=(?:[1-9]|10)',
   'PRODUCTS_READ'=>$root.'/products.json\?page=[1-3]&limit=(?:[1-9]|10)','ORDERS_READ'=>$root.'/orders.json\?page=[1-3]&limit=(?:[1-9]|10)','ORDER_READ'=>$root.'/orders/'.$id.'.json','WEBHOOKS_READ'=>$root.'/webhooks.json','WEBHOOK_READ'=>$root.'/webhooks.json',
   'SUPPORT_LIST'=>$root.'/orders/'.$id.'/support-requests','SUPPORT_DETAIL'=>$root.'/orders/'.$id.'/support-requests/'.$id];
  $op=$r['operation']??'';$path=$r['endpoint']??null;
  // Dot is literal everywhere; no percent escapes, alternate host, query keys or path normalization.
  if(!is_string($path)||strlen($path)>600||!isset($rules[$op])||preg_match('#^'.str_replace('.json','\\.json',$rules[$op]).'$#D',$path)!==1)throw new \InvalidArgumentException('Fixed endpoint required');
  $page=1;if(preg_match('/[?]page=([1-3])&limit=([1-9]|10)$/D',$path,$m))$page=(int)$m[1];
  $template=str_replace([$root,$n,$id,'(?:standard|priority|express|economy)','[1-3]','(?:[1-9]|10)','\\?'],['/v1/shops/{shop}','{id}','{id}','{method}','{page}','{limit}','?'],$rules[$op]);
  return ['operation'=>$op,'method'=>'GET','api_version'=>str_starts_with($path,'/v2/')?'v2':'v1','endpoint_template'=>$template,'endpoint_sha256'=>hash('sha256',$path),'page'=>$page];
 }
 public static function observations(mixed $data):array {
  if(!is_array($data))return ['container'=>'none'];$fields=[];
  foreach(['id','data','variants','print_areas','size_guide','shipping','status','line_items','current_page','last_page','per_page','support_requests','support_request','subscriptions','production_time','tracking','images','blueprint_id','print_provider_id'] as $key){$fields[$key]=array_key_exists($key,$data)?get_debug_type($data[$key]):'absent';}
  return ['container'=>array_is_list($data)?'list':'object','item_count'=>array_is_list($data)?min(count($data),1000):null,'fields'=>$fields,'values_disclosed'=>false];
 }
}
