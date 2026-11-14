<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Pure request validation and minimal evidence. No transport, approval, or eligibility authority. */
final class PrintifySupportContract
{
 private const OPS=['SUPPORT_LIST','SUPPORT_DETAIL','SUPPORT_REPRINT','SUPPORT_REFUND','SUPPORT_ADDRESS_CHANGE'];
 private static function fail():never {throw new \InvalidArgumentException('Invalid or unbound support request evidence.');}
 private static function id(mixed $v):string {if(!is_string($v)||!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$v))self::fail();return $v;}
 private static function text(mixed $v,int $max,bool $empty=false):string {if(!is_string($v)||strlen($v)>$max*4||preg_match('//u',$v)!==1||mb_strlen($v,'UTF-8')>$max||(!$empty&&trim($v)==='')||preg_match('/[\x00-\x1f\x7f]/',$v))self::fail();return $v;}
 private static function keys(array $v,array $allowed):void {if(array_diff(array_keys($v),$allowed))self::fail();}
 private static function url(mixed $v):string {
  $v=self::text($v,2048);$p=parse_url($v);if(!is_array($p)||($p['scheme']??'')!=='https'||isset($p['user'],$p['pass'])||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||isset($p['port'])&&$p['port']!==443)self::fail();
  $h=strtolower($p['host']??'');if(!str_contains($h,'.')||str_ends_with($h,'.')||preg_match('/(?:^|\.)(localhost|local|internal|test|invalid)$/',$h)||!preg_match('/^[a-z0-9.-]+$/D',$h))self::fail();
  if(filter_var($h,FILTER_VALIDATE_IP)){if(!filter_var($h,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))self::fail();}elseif(!filter_var($h,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)||preg_match('/^[0-9.]+$/D',$h))self::fail();return $v;
 }
 public static function prepare(string $operation,string $providerShop,array $input):array {
  if(!in_array($operation,self::OPS,true)||!preg_match('/^[1-9][0-9]{0,18}$/D',$providerShop))self::fail();
  $order=self::id($input['order_id']??null);$scope=['provider_shop_id'=>$providerShop,'order_id'=>$order];$endpoint='/v1/shops/'.$providerShop.'/orders/'.$order.'/support-requests';$body=[];$method='GET';
  if($operation==='SUPPORT_LIST')self::keys($input,['order_id']);
  elseif($operation==='SUPPORT_DETAIL'){self::keys($input,['order_id','support_request_id']);$scope['support_request_id']=self::id($input['support_request_id']??null);$endpoint.='/'.$scope['support_request_id'];}
  elseif(in_array($operation,['SUPPORT_REPRINT','SUPPORT_REFUND'],true)){
   self::keys($input,['order_id','reason','description','image_urls','line_items']);$reason=$input['reason']??null;if(!in_array($reason,['image_quality','incorrect_item','shipping_issues','stained_or_damaged_item','wrong_color_or_size','wrong_design'],true))self::fail();
   $body=['reason'=>$reason,'description'=>self::text($input['description']??null,400)];
   if(isset($input['image_urls'])){if(!is_array($input['image_urls'])||!array_is_list($input['image_urls'])||count($input['image_urls'])>5)self::fail();$body['image_urls']=array_map(self::url(...),$input['image_urls']);if(count(array_unique($body['image_urls']))!==count($body['image_urls']))self::fail();}
   $lines=$input['line_items']??null;if(!is_array($lines)||!array_is_list($lines)||count($lines)<1||count($lines)>100)self::fail();$seen=[];$body['line_items']=[];
   foreach($lines as $line){if(!is_array($line))self::fail();self::keys($line,['line_item_id','quantity']);$id=self::id($line['line_item_id']??null);$q=$line['quantity']??null;if(isset($seen[$id])||!is_int($q)||$q<1||$q>10000)self::fail();$seen[$id]=true;$body['line_items'][]=['line_item_id'=>$id,'quantity'=>$q];}
   $method='POST';$endpoint.=$operation==='SUPPORT_REPRINT'?'/reprint':'/refund';
  }else{
   $fields=['first_name','last_name','address1','address2','city','region','country','zip','email','phone'];self::keys($input,array_merge(['order_id'],$fields));
   foreach($fields as $field){$required=in_array($field,['first_name','last_name','address1','city','country','zip'],true);if($required||array_key_exists($field,$input))$body[$field]=self::text($input[$field]??null,254,!$required);}
   if(!in_array($body['country'],explode(' ', 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW'),true)||isset($body['email'])&&$body['email']!==''&&!filter_var($body['email'],FILTER_VALIDATE_EMAIL))self::fail();$method='POST';$endpoint.='/address-change';
  }
  $r=['operation'=>$operation,'method'=>$method,'endpoint'=>$endpoint,'body'=>$body,'scope'=>$scope,'scope_identity'=>$scope,'network_request_permitted'=>false,'external_execution_authorized'=>false,'required_scope'=>$method==='GET'?'orders.read':'orders.write'];$r['fingerprint']=hash('sha256',\DigiForge\Listings\Validator::canonicalJson($r));return $r;
 }
 public static function intact(array $request):bool {
  try {$scope=$request['scope']??null;if(!is_array($scope))return false;$input=['order_id'=>$scope['order_id']??null];if(($request['operation']??null)==='SUPPORT_DETAIL')$input['support_request_id']=$scope['support_request_id']??null;
   $body=$request['body']??null;if(!is_array($body))return false;if(array_intersect(array_keys($input),array_keys($body)))return false;$input=array_merge($input,$body);return \DigiForge\Listings\Validator::canonicalJson(self::prepare($request['operation']??'', $scope['provider_shop_id']??'', $input))===\DigiForge\Listings\Validator::canonicalJson($request);
  }catch(\Throwable){return false;}
 }
 /** Checks observed line membership only; provider decides whether to grant a request. */
 public static function validateEligibility(string $operation,array $input,array $projectedOrder):void {
  if(!in_array($operation,self::OPS,true)||($projectedOrder['id']??null)!==($input['order_id']??null))self::fail();
  if(!in_array($operation,['SUPPORT_REPRINT','SUPPORT_REFUND'],true))return;
  $lines=$projectedOrder['line_items']??null;if(!is_array($lines)||!array_is_list($lines)||count($lines)>100)self::fail();$available=[];
  foreach($lines as $line){if(!is_array($line))self::fail();$id=self::id($line['id']??null);$q=$line['quantity']??null;if(isset($available[$id])||!is_int($q)||$q<1)self::fail();$available[$id]=$q;}
  $requested=$input['line_items']??null;if(!is_array($requested)||!array_is_list($requested)||count($requested)<1||count($requested)>100)self::fail();$seen=[];
  foreach($requested as $line){if(!is_array($line))self::fail();$id=self::id($line['line_item_id']??null);$q=$line['quantity']??null;if(isset($seen[$id])||!is_int($q)||$q<1||!isset($available[$id])||$q>$available[$id])self::fail();$seen[$id]=true;}
 }
 public static function project(array $request,mixed $data,int $httpStatus):array {
  if(!self::intact($request))self::fail();
  $op=$request['operation']??null;$scope=$request['scope']??null;if(!in_array($op,self::OPS,true)||!is_array($scope))self::fail();self::id($scope['order_id']??null);
  $out=['operation'=>$op,'provider_shop_id'=>$scope['provider_shop_id']??null,'order_id'=>$scope['order_id'],'http_status'=>$httpStatus,'request_fingerprint'=>$request['fingerprint']??null,'refund_guaranteed'=>false,'production_certified'=>false];
  if($httpStatus<200||$httpStatus>=300)return $out+['result'=>'provider_error','error_category'=>match($httpStatus){400=>'validation_failed',404=>'not_found',default=>'unknown_error'}];
  if(!is_array($data))self::fail();
  if($op==='SUPPORT_LIST'){if($httpStatus!==200||!isset($data['data'])||!is_array($data['data'])||!array_is_list($data['data'])||count($data['data'])>100)self::fail();$items=[];$seen=[];foreach($data['data'] as $item){$item=self::item($item,$scope);if(isset($seen[$item['id']]))self::fail();$seen[$item['id']]=true;$items[]=$item;}return $out+['result'=>'observed','support_requests'=>$items,'complete_list_verified'=>false];}
  if($op==='SUPPORT_DETAIL'){if($httpStatus!==200)self::fail();$item=self::item($data['data']??null,$scope);if($item['id']!==($scope['support_request_id']??null))self::fail();return $out+['result'=>'observed','support_request'=>$item];}
  if($op==='SUPPORT_ADDRESS_CHANGE'&&$httpStatus===200){if(($data['resolution']??null)!=='updated_directly'||($data['order_id']??null)!==$scope['order_id']||!is_array($data['address_to']??null))self::fail();foreach($request['body'] as $key=>$value)if(($data['address_to'][$key]??null)!==$value)self::fail();return $out+['result'=>'address_updated','resolution'=>'updated_directly','address_sha256'=>hash('sha256',\DigiForge\Listings\Validator::canonicalJson($request['body']))];}
  if($httpStatus!==201)self::fail();$item=self::item($data,$scope,$op==='SUPPORT_ADDRESS_CHANGE');$type=match($op){'SUPPORT_REPRINT'=>'order_reprint','SUPPORT_REFUND'=>'order_refund',default=>'order_address_editing'};if($item['type']!==$type||$op==='SUPPORT_ADDRESS_CHANGE'&&($data['resolution']??null)!=='support_request_created')self::fail();
  if(in_array($op,['SUPPORT_REPRINT','SUPPORT_REFUND'],true)){$expected=array_column($request['body']['line_items'],'line_item_id');$observed=$item['line_item_ids'];sort($expected);sort($observed);if($expected!==$observed)self::fail();}
  return $out+['result'=>'request_acknowledged','resolution'=>$op==='SUPPORT_ADDRESS_CHANGE'?'support_request_created':'support_request_acknowledged','support_request'=>$item];
 }
 private static function item(mixed $data,array $scope,bool $address=false):array {
  if(!is_array($data)||($data['order_id']??null)!==$scope['order_id'])self::fail();$id=self::id($data['id']??null);$type=$data['type']??null;$status=$data['status']??null;$outcome=$data['outcome']??null;
  if(!in_array($type,['order_cancellation','order_address_editing','order_shipping_upgrade','order_has_issues','order_tracking_status','order_refund','order_reprint'],true)||!in_array($status,['pending','processing','in_progress','resolved'],true)||!in_array($outcome,['positive','negative','unresolved'],true)||$status!=='resolved'&&$outcome!=='unresolved')self::fail();
  $item=['id'=>$id,'order_id'=>$scope['order_id'],'type'=>$type,'status'=>$status==='processing'?'pending':$status,'source_status'=>$status,'outcome'=>$outcome];
  if(!$address){$ids=$data['line_item_ids']??null;if(!is_array($ids)||!array_is_list($ids)||count($ids)>100)self::fail();$item['line_item_ids']=array_map(self::id(...),$ids);if(count(array_unique($ids))!==count($ids))self::fail();}
  foreach(['created_at','updated_at'] as $k){$value=self::text($data[$k]??null,64);if(!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:?\d{2})$/D',$value)||strtotime($value)===false)self::fail();$item[$k]=$value;}
  // Provider descriptions, error messages, customer details and addresses are deliberately omitted.
  return $item;
 }
}
