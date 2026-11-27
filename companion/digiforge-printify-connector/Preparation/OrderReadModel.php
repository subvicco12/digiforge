<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
use DigiForge\Connector\Printify\ShopBinding;
/** PII-minimized immutable observation. No ordering/fulfillment authority or invented monetary values. */
final readonly class OrderReadModel implements \JsonSerializable {
 private function __construct(private array $data){}
 public static function fromProvider(ShopBinding $s,array $o,array $lineage,string $readReceipt):self {
  self::id($o['id']??null);if(isset($o['shop_id'])&&(!is_int($o['shop_id'])||(string)$o['shop_id']!==$s->providerShopId))self::bad();self::digest($readReceipt);
  $status=$o['status']??null;if(!is_string($status)||!preg_match('/^[a-z_-]{1,60}$/D',$status))self::bad();
  $states=['pending','on-hold','sending-to-production','in-production','canceled','fulfilled','partially-fulfilled','payment-not-received','has-issues','cost-calculation','unfulfillable','sending_to_production_delegate','sending_to_production_delegate_sync','source-check-failed'];
  $normalized=in_array($status,$states,true)?strtoupper(str_replace('-','_',$status)):'UNKNOWN';$blockers=$normalized==='UNKNOWN'?['ORDER_STATUS_UNKNOWN']:[];
  $a=$o['address_to']??null;if(!is_array($a))self::bad();foreach(['first_name','last_name','address1','city','zip']as $k)self::text($a[$k]??null,255,$k==='zip');
  $countries='AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW';
  if(!is_string($a['country']??null)||!in_array($a['country'],explode(' ',$countries),true))self::bad();if(in_array($a['country'],['US','CA','GB'],true)&&trim($a['zip'])==='')self::bad();
  foreach(['region','address2','phone','company']as $k)if(isset($a[$k]))self::text($a[$k],255,true);
  if(isset($a['email'])&&(!is_string($a['email'])||strlen($a['email'])>254||filter_var($a['email'],FILTER_VALIDATE_EMAIL)===false))self::bad();
  $rows=$o['line_items']??null;if(!is_array($rows)||!array_is_list($rows)||$rows===[]||count($rows)>100||!array_is_list($lineage)||count($lineage)>100)self::bad();$mapped=[];
  foreach($lineage as $l){if(!is_array($l))self::bad();self::id($l['product_id']??null);self::id($l['order_id']??null);self::id($l['line_item_id']??null);if(!is_int($l['print_provider_id']??null)||$l['print_provider_id']<1||!is_int($l['variant_id']??null)||$l['variant_id']<1||!is_int($l['product_version']??null)||$l['product_version']<1||!is_bool($l['personalization_required']??null)||!in_array($l['proof_status']??null,['APPROVED','UNKNOWN','REJECTED'],true))self::bad();if(($l['proof_receipt']??null)!==null)self::digest($l['proof_receipt']);$key=$l['line_item_id'];if(isset($mapped[$key]))self::bad();$mapped[$key]=$l;}
  $items=[];$seenLines=[];foreach($rows as $r){if(!is_array($r))self::bad();self::id($r['product_id']??null);self::id($r['id']??null);if(isset($seenLines[$r['id']]))self::bad();$seenLines[$r['id']]=true;foreach(['variant_id','print_provider_id','quantity']as $k)if(!is_int($r[$k]??null)||$r[$k]<1)self::bad();if($r['quantity']>1000)self::bad();$l=$mapped[$r['id']]??null;if($l!==null&&($l['order_id']!==$o['id']||$l['product_id']!==$r['product_id']||$l['variant_id']!==$r['variant_id']))$l=null;
   if($l===null)$blockers[]='PRODUCT_LINEAGE_MISSING';elseif($l['print_provider_id']!==$r['print_provider_id'])$blockers[]='PRODUCT_PROVIDER_MISMATCH';elseif($l['personalization_required']&&(($l['proof_receipt']??null)===null||$l['proof_status']!=='APPROVED'))$blockers[]='PERSONALIZATION_PROOF_MISSING';
   $items[]=['line_item_id'=>$r['id'],'product_id'=>$r['product_id'],'variant_id'=>$r['variant_id'],'print_provider_id'=>$r['print_provider_id'],'quantity'=>$r['quantity'],'product_version'=>$l['product_version']??null,'proof_receipt'=>$l['proof_receipt']??null];
  }
  $external=$o['metadata']['shop_order_id']??null;if($external!==null)self::id($external);else $blockers[]='EXTERNAL_ORDER_REFERENCE_MISSING';
  $physical=[$s->shopKey,$s->integrationId,$s->providerShopId,$s->environment];
  $data=['schema'=>'DF_ORDER_OBSERVATION_1','order_id'=>$o['id'],'scope_fingerprint'=>$s->fingerprint(),'provider_identity'=>MutationIntent::hash([$physical,$o['id']]),'external_identity'=>$external===null?null:MutationIntent::hash([$physical,$external]),'status'=>$normalized,'address_valid'=>true,'address_validation'=>'STRUCTURAL_ONLY_PROVIDER_UNVERIFIED','items'=>$items,'blockers'=>array_values(array_unique($blockers)),'manual_review_required'=>true,'lineage_verification_required'=>true,'read_receipt'=>$readReceipt,'read_only'=>true,'external_execution_authorized'=>false];$business=$data;unset($business['read_receipt'],$business['scope_fingerprint']);return new self($data+['content_hash'=>MutationIntent::hash($business)]);
 }
 public function jsonSerialize():array{return $this->data;}
 private static function id(mixed $v):void{if(!is_string($v)||!preg_match('/^[A-Za-z0-9_.:-]{1,160}$/D',$v))self::bad();}
 private static function digest(mixed $v):void{if(!is_string($v)||!preg_match('/^[a-f0-9]{64}$/D',$v))self::bad();}
 private static function text(mixed $v,int $max,bool $empty=false):void{if(!is_string($v)||strlen($v)>$max||(!$empty&&trim($v)==='')||preg_match('//u',$v)!==1||preg_match('/[<>\x00-\x1F\x7F]/',$v))self::bad();}
 private static function bad():never{throw new \InvalidArgumentException('Exact scoped order, structural address and bounded line-item evidence required.');}
}
