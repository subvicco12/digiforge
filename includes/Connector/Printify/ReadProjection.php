<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
/** Explicit field projection: customer identities, addresses, tracking IDs and upload URLs never leave this boundary. */
final class ReadProjection {
 public static function project(ExternalOperationIntent $intent,ShopBinding $binding,array $body):array {
  $op=$intent->operation;$list=in_array($op,['SHOPS_READ','BLUEPRINTS_READ','PROVIDERS_READ','PRODUCTS_READ','ORDERS_READ','UPLOADS_READ','VARIANTS_READ'],true);
  if(in_array($op,['PRODUCTS_READ','ORDERS_READ','UPLOADS_READ'],true)){$rows=$body['data']??null;if(!is_array($rows)||!array_is_list($rows)||count($rows)>($intent->input['limit']??10))throw new \InvalidArgumentException('Bounded data page required.');}
  elseif($op==='VARIANTS_READ'){$rows=$body['variants']??null;if(!is_array($rows)||!array_is_list($rows)||count($rows)>2000)throw new \InvalidArgumentException('Bounded variants required.');}
  elseif($list){$rows=$body;if(!array_is_list($rows)||count($rows)>2000)throw new \InvalidArgumentException('Bounded list required.');}
  elseif($op==='SHIPPING_READ')return ['items'=>self::shipping($body,$intent->input['shipping_method']??null),'pagination'=>null];
  else{$rows=[$body];}
  $items=[];
  foreach($rows as $row){if(!is_array($row)||!array_key_exists('id',$row)||(!is_string($row['id'])&&!is_int($row['id']))||!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',(string)$row['id']))throw new \InvalidArgumentException('Provider identity required.');
   if(isset($row['shop_id'])&&(string)$row['shop_id']!==$binding->providerShopId)throw new \InvalidArgumentException('Provider shop mismatch.');
   if(in_array($op,['UPLOAD_READ','UPLOADS_READ'],true)&&!in_array((string)$row['id'],$binding->ownedUploads,true)){if($op==='UPLOAD_READ')throw new \InvalidArgumentException('Owned upload receipt required.');continue;}
   if($op==='SHOPS_READ'&&(string)$row['id']!==$binding->providerShopId)continue;
   $expected=match($op){'PRODUCT_READ'=>$intent->input['product_id']??null,'ORDER_READ'=>$intent->input['order_id']??null,'UPLOAD_READ'=>$intent->input['upload_id']??null,'BLUEPRINT_READ'=>$intent->input['blueprint_id']??null,'PROVIDER_READ'=>$intent->input['provider_id']??null,default=>null};
   if($expected!==null&&(string)$row['id']!==(string)$expected)throw new \InvalidArgumentException('Resource mismatch.');
   $out=['id'=>(string)$row['id']];
   foreach(['title','name','status','created_at','updated_at'] as $key)if(isset($row[$key])){$out[$key]=self::text($row[$key]);}
   foreach(['blueprint_id','print_provider_id','width','height','size'] as $key)if(isset($row[$key])){if(!is_int($row[$key])||$row[$key]<0)throw new \InvalidArgumentException('Metadata type mismatch.');$out[$key]=$row[$key];}
   foreach(['is_enabled','is_available','visible'] as $key)if(isset($row[$key])){if(!is_bool($row[$key]))throw new \InvalidArgumentException('Metadata type mismatch.');$out[$key]=$row[$key];}
   if(isset($row['variants'])){$out['variants']=self::variants($row['variants']);}
   if(isset($row['options'])){$out['options']=self::options($row['options']);}
   if(isset($row['print_areas'])){$out['print_areas']=self::printAreas($row['print_areas']);}
   if(isset($row['placeholders'])){$out['placeholders']=self::geometry($row['placeholders']);}
   // Do not imply personalization assets, artwork rights, costs or provider certification.
   if(in_array($op,['ORDER_READ','ORDERS_READ'],true)){$out['line_item_count']=isset($row['line_items'])&&is_array($row['line_items'])?count($row['line_items']):null;$out['shipment_count']=isset($row['shipments'])&&is_array($row['shipments'])?count($row['shipments']):null;}
   $items[]=$out;
  }
  $pagination=null;
  if(in_array($op,['PRODUCTS_READ','ORDERS_READ','UPLOADS_READ'],true)){$page=$intent->input['page']??1;$limit=$intent->input['limit']??10;if(isset($body['current_page'])&&$body['current_page']!==$page)throw new \InvalidArgumentException('Pagination mismatch.');$last=$body['last_page']??null;if($last!==null&&(!is_int($last)||$last<$page))throw new \InvalidArgumentException('Pagination malformed.');$pagination=['page'=>$page,'limit'=>$limit,'has_more'=>$last===null?null:$page<$last,'next_page'=>$last!==null&&$page<$last&&$page<3?$page+1:null,'maximum_page'=>3];}
  return ['items'=>$items,'pagination'=>$pagination];
 }
 private static function text(mixed $v):string{if(!is_string($v)||strlen($v)>512||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$v))throw new \InvalidArgumentException('Bounded metadata required.');return $v;}
 private static function variants(mixed $v):array{if(!is_array($v)||!array_is_list($v)||count($v)>2000)throw new \InvalidArgumentException('Variants malformed.');$out=[];foreach($v as $r){if(!is_array($r)||!is_int($r['id']??null)||$r['id']<1)throw new \InvalidArgumentException('Variant identity required.');$n=['id'=>$r['id']];foreach(['is_enabled','is_available'] as $k)if(isset($r[$k])){if(!is_bool($r[$k]))throw new \InvalidArgumentException('Variant flag malformed.');$n[$k]=$r[$k];}if(isset($r['options']))$n['options']=self::options($r['options']);if(isset($r['placeholders']))$n['placeholders']=self::geometry($r['placeholders']);$out[]=$n;}return $out;}
 private static function options(mixed $v):array {
  if(!is_array($v)||count($v)>100)throw new \InvalidArgumentException('Options malformed.');$out=[];
  foreach($v as $k=>$r){
   if(is_string($r)){$out[$k]=self::text($r);continue;}
   if(is_int($r)&&$r>=0){$out[$k]=$r;continue;}
   if(!is_array($r))throw new \InvalidArgumentException('Option malformed.');$n=[];
   foreach(['name','type','title'] as $f)if(isset($r[$f]))$n[$f]=self::text($r[$f]);
   if(isset($r['values'])){
    if(!is_array($r['values'])||!array_is_list($r['values'])||count($r['values'])>100)throw new \InvalidArgumentException('Option values malformed.');$n['values']=[];
    foreach($r['values'] as $value){
     if(is_string($value)){$n['values'][]=self::text($value);continue;}
     if(is_int($value)&&$value>=0){$n['values'][]=$value;continue;}
     if(!is_array($value)||!is_int($value['id']??null)||$value['id']<1||!is_string($value['title']??null))throw new \InvalidArgumentException('Option value identity malformed.');
     $clean=['id'=>$value['id'],'title'=>self::text($value['title'])];
     if(isset($value['colors'])){if(!is_array($value['colors'])||!array_is_list($value['colors'])||count($value['colors'])>10)throw new \InvalidArgumentException('Colors malformed.');$clean['colors']=array_map(static function($c){if(!is_string($c)||!preg_match('/^#[a-fA-F0-9]{3}(?:[a-fA-F0-9]{3})?$/D',$c))throw new \InvalidArgumentException('Color malformed.');return $c;},$value['colors']);}
     $n['values'][]=$clean;
    }
   }$out[$k]=$n;
  }return $out;
 }
 private static function printAreas(mixed $v):array {
  if(!is_array($v)||!array_is_list($v)||count($v)>100)throw new \InvalidArgumentException('Print areas malformed.');$out=[];
  foreach($v as $area){
   if(!is_array($area)||!is_array($area['variant_ids']??null)||!array_is_list($area['variant_ids'])||count($area['variant_ids'])>2000||!is_array($area['placeholders']??null)||!array_is_list($area['placeholders'])||count($area['placeholders'])>100)throw new \InvalidArgumentException('Print area mapping malformed.');
   $ids=[];foreach($area['variant_ids'] as $id){if(!is_int($id)||$id<1)throw new \InvalidArgumentException('Variant identity malformed.');$ids[]=$id;}$positions=[];
   foreach($area['placeholders'] as $place){if(!is_array($place)||!is_string($place['position']??null)||!is_array($place['images']??null)||!array_is_list($place['images'])||count($place['images'])>10)throw new \InvalidArgumentException('Placement malformed.');$images=[];
    foreach($place['images'] as $image){if(!is_array($image)||!is_string($image['id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$image['id']))throw new \InvalidArgumentException('Image reference malformed.');$n=['id'=>$image['id']];foreach(['x','y','scale','angle'] as $f){$number=$image[$f]??null;if((!is_int($number)&&!is_float($number))||!is_finite((float)$number)||abs((float)$number)>1000||($f==='scale'&&$number<=0))throw new \InvalidArgumentException('Placement evidence malformed.');$n[$f]=$number;}$images[]=$n;}
    $positions[]=['position'=>self::text($place['position']),'images'=>$images];
   }$out[]=['variant_ids'=>$ids,'placeholders'=>$positions];
  }return $out;
 }
 private static function geometry(mixed $v):array{if(!is_array($v)||!array_is_list($v)||count($v)>100)throw new \InvalidArgumentException('Geometry malformed.');$out=[];foreach($v as $r){if(!is_array($r)||!is_string($r['position']??null)||!is_int($r['width']??null)||!is_int($r['height']??null)||$r['width']<1||$r['height']<1)throw new \InvalidArgumentException('Geometry dimensions required.');$out[]=['position'=>self::text($r['position']),'width'=>$r['width'],'height'=>$r['height']];}return $out;}
 private static function shipping(array $body,?string $method):array {
  $rows=$body['data']??null;if(!is_array($rows)||!array_is_list($rows)||count($rows)>2000)throw new \InvalidArgumentException('V2 shipping data required.');$out=[];
  foreach($rows as $row){
   if(!is_array($row)||!is_string($row['id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$row['id'])||!is_array($row['attributes']??null))throw new \InvalidArgumentException('Shipping identity required.');$a=$row['attributes'];
   if($method===null){if(($row['type']??null)!=='shipping_method'||!in_array($a['name']??null,['standard','priority','express','economy'],true))throw new \InvalidArgumentException('Shipping method mismatch.');$out[]=['id'=>$row['id'],'name'=>$a['name']];continue;}
   if(!is_string($row['type']??null)||!preg_match('/^variant_shipping_[a-z_]{1,64}$/D',$row['type'])||($a['shippingType']??null)!==$method||!is_int($a['variantId']??null)||$a['variantId']<1||!is_string($a['country']['code']??null)||!preg_match('/^(?:[A-Z]{2}|REST_OF_THE_WORLD)$/D',$a['country']['code']))throw new \InvalidArgumentException('Regional shipping scope mismatch.');
   $n=['id'=>$row['id'],'shipping_method'=>$method,'variant_id'=>$a['variantId'],'country'=>$a['country']['code']];
   if(isset($a['handlingTime'])){if(!is_array($a['handlingTime'])||!is_int($a['handlingTime']['from']??null)||!is_int($a['handlingTime']['to']??null)||$a['handlingTime']['from']<0||$a['handlingTime']['to']<$a['handlingTime']['from']||$a['handlingTime']['to']>365)throw new \InvalidArgumentException('Handling time malformed.');$n['handling_time']=['from'=>$a['handlingTime']['from'],'to'=>$a['handlingTime']['to']];}
   if(isset($a['shippingCost'])){if(!is_array($a['shippingCost']))throw new \InvalidArgumentException('Shipping cost malformed.');$cost=[];foreach(['firstItem'=>'first_item','additionalItems'=>'additional_items'] as $source=>$target){if(!isset($a['shippingCost'][$source]))continue;$money=$a['shippingCost'][$source];if(!is_array($money)||!is_int($money['amount']??null)||$money['amount']<0||!is_string($money['currency']??null)||!preg_match('/^[A-Z]{3}$/D',$money['currency']))throw new \InvalidArgumentException('Explicit shipping money required.');$cost[$target]=['amount'=>$money['amount'],'currency'=>$money['currency']];}$n['shipping_cost']=$cost;}
   $out[]=$n;
  }return $out;
 }
}
