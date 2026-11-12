<?php
declare(strict_types=1);
namespace DigiForge\POD;
/** Bounded evidence projection; URLs are observations and are never followed automatically. */
final class PrintifyProductResponse {
 public static function project(array $plan,array $data):array {
  $op=$plan['request']['operation'];$body=$plan['request']['body'];$options=$plan['options'];
  if(in_array($op,['PRODUCT_PUBLISH','PRODUCT_DELETE','PRODUCT_UNPUBLISH_NOTIFY','PRODUCT_PUBLISH_SUCCEEDED','PRODUCT_PUBLISH_FAILED'],true)){
   if($data!==[])self::bad();preg_match('#/products/([^/.]+)#',$plan['request']['endpoint'],$match);
   return ['id'=>$match[1]??'', 'request_acknowledged'=>true,'operation'=>$op,'external_listing_state_verified'=>false,'publication_state'=>$op==='PRODUCT_PUBLISH'?'PENDING_READBACK':($op==='PRODUCT_UNPUBLISH_NOTIFY'?'NOTIFICATION_ONLY':($op==='PRODUCT_DELETE'?'DELETE_ACKNOWLEDGED':'CHANNEL_STATUS_ACKNOWLEDGED'))];
  }
  if(in_array($op,['IMAGE_UPLOAD','IMAGE_READ'],true)){
   self::id($data['id']??null);foreach(['width','height','size'] as $k)if(!is_int($data[$k]??null)||$data[$k]<1)self::bad();
   if($op==='IMAGE_UPLOAD'){$bytes=base64_decode($body['contents'],true);$h=unpack('Nwidth/Nheight',substr($bytes,16,8));if($data['width']!==$h['width']||$data['height']!==$h['height']||$data['size']!==strlen($bytes)||($data['mime_type']??null)!=='image/png')self::bad();}
   if($op==='IMAGE_READ'&& !str_ends_with($plan['request']['endpoint'],'/'.$data['id'].'.json'))self::bad();
   $url=$data['preview_url']??null;if($url!==null)self::url($url);return array_intersect_key($data,array_flip(['id','width','height','size','mime_type','file_name','preview_url','upload_time']));
  }
  if(in_array($op,['PRODUCT_CREATE','PRODUCT_UPDATE','PRODUCT_READ'],true)){
   self::id($data['id']??null);if($op!=='PRODUCT_CREATE'&&!str_ends_with($plan['request']['endpoint'],'/'.$data['id'].'.json'))self::bad();
   if(($data['blueprint_id']??null)!==$plan['selection']['blueprint_id']||($data['print_provider_id']??null)!==$plan['selection']['provider_id'])self::bad();
   $variants=$data['variants']??null;if(!is_array($variants)||!array_is_list($variants)||count($variants)>1000)self::bad();$rows=[];$seen=[];foreach($variants as $v){if(!is_int($v['id']??null)||$v['id']<1||isset($seen[$v['id']])||!is_int($v['price']??null)||$v['price']<0||!is_bool($v['is_enabled']??null))self::bad();$seen[$v['id']]=true;$rows[]=array_intersect_key($v,array_flip(['id','price','is_enabled']));}
   foreach($body['variants']??[] as $expected){$found=false;foreach($rows as $row)if($row['id']===$expected['id']&&$row['price']===$expected['price']&&$row['is_enabled']===$expected['is_enabled'])$found=true;if(!$found)self::bad();}
   if(!isset($seen[$plan['selection']['variant_id']]))self::bad();$areas=$data['print_areas']??null;if(!is_array($areas)||count($areas)>100)self::bad();
   foreach($body['print_areas']??[] as $expectedArea)foreach($expectedArea['placeholders'] as $expectedHolder)foreach($expectedHolder['images'] as $expectedImage){$found=false;foreach($areas as $area)if(in_array($plan['selection']['variant_id'],$area['variant_ids']??[],true))foreach($area['placeholders']??[] as $holder)if(($holder['position']??null)===$expectedHolder['position'])foreach($holder['images']??[] as $image){$same=is_string($image['id']??null)&&$image['id']===$expectedImage['id'];foreach(['x','y','scale','angle'] as $key)if((!is_int($image[$key]??null)&&!is_float($image[$key]??null))||!is_finite((float)$image[$key])||(float)$image[$key]!== (float)$expectedImage[$key])$same=false;if($same)$found=true;}if(!$found)self::bad();}
   $images=$data['images']??[];if(!is_array($images)||!array_is_list($images)||count($images)>100)self::bad();foreach($images as $image)self::url($image['src']??null);
   return ['id'=>$data['id'],'blueprint_id'=>$data['blueprint_id'],'print_provider_id'=>$data['print_provider_id'],'variants'=>$rows,'print_areas'=>$areas,'images'=>$images,'supplier_certified'=>false];
  }
  if($op==='PERSONALIZATION_OPTIONS'){
   if(!array_is_list($data)||count($data)>30)self::bad();$fields=[];$seen=[];foreach($data as $f){self::id($f['field_id']??null);if(isset($seen[$f['field_id']])||!in_array($f['type']??null,['textbox','image'],true))self::bad();$seen[$f['field_id']]=true;$limit=$f['text_options']['character_limit']??null;if($limit!==null&&(!is_int($limit)||$limit<1||$limit>2000))self::bad();$fields[]=['field_id'=>$f['field_id'],'type'=>$f['type'],'label'=>is_string($f['label']??null)?substr($f['label'],0,300):null,'character_limit'=>$limit];}
   preg_match('#/products/([^/]+)/#',$plan['request']['endpoint'],$m);return ['product_id'=>$m[1],'fields'=>$fields];
  }
  if($op==='PERSONALIZATION_CONFIGURE'){
   foreach(['personalisation_strategy','personalisation_instructions'] as $k)if(!is_string($data[$k]??null)||$data[$k]===''||strlen($data[$k])>2000)self::bad();return array_intersect_key($data,array_flip(['personalisation_strategy','personalisation_instructions']));
  }
  self::id($data['task_id']??null);$status=$data['status']??null;if(!in_array($status,['pending','processing','completed','failed','corrupt'],true))self::bad();
  if($op==='PREVIEW_READ'&&!str_ends_with($plan['request']['endpoint'],'/'.$data['task_id'].'.json'))self::bad();
  $images=$data['mockups']??[];if(!is_array($images)||!array_is_list($images)||count($images)>100)self::bad();foreach($images as $image){if(($image['variant_id']??null)!==$plan['selection']['variant_id'])self::bad();self::url($image['src']??null);}
  preg_match('#/products/([^/]+)/#',$plan['request']['endpoint'],$m);return ['product_id'=>$m[1],'task_id'=>$data['task_id'],'status'=>$status,'mockups'=>$images,'image_bytes_verified'=>false,'supplier_certified'=>false];
 }
 private static function id(mixed $id):void{if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$id))self::bad();}
 private static function url(mixed $url):void{if(!is_string($url)||strlen($url)>2048||!filter_var($url,FILTER_VALIDATE_URL))self::bad();$p=parse_url($url);if(($p['scheme']??null)!=='https'||isset($p['user'])||isset($p['pass'])||isset($p['fragment']))self::bad();}
 private static function bad():never{throw new \InvalidArgumentException('Provider response does not match its exact operation scope.');}
}
