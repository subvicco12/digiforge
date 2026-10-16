<?php
declare(strict_types=1);
namespace DigiForge\Research;

/** Offline simulator only. No transport, registration, credential access or safety exception. */
final class TinyFishResearchContract
{
 public const VERSION='tinyfish-printify-offline-v1';
 public const FAMILIES=['PP-002','PP-011','PP-003','PP-009','PP-013'];
 private const FIELDS=['shop','environment','integration_id','provider_shop_id','account_sha256','session_sha256','approval_sha256','scope_revision_sha256','expires_at','max_requests','max_session_seconds','max_cost_usd_micros','rate_usd_micros_per_minute','rate_evidence_sha256','families','destinations'];
 public function __construct(private readonly string $checkpointKey){if(strlen($checkpointKey)<32)throw new \InvalidArgumentException('Independent checkpoint key required.');}
 public function __debugInfo():array{return ['offline_only'=>true];}
 public function __serialize():array{throw new \LogicException('Research contract secrets are not serializable.');}
 public function start(array $scope,int $now):array
 {
  $this->scope($scope,$now);$reserved=intdiv($scope['max_session_seconds']+59,60)*$scope['rate_usd_micros_per_minute'];
  if($reserved>$scope['max_cost_usd_micros'])throw self::denied();
  return $this->seal(['schema'=>self::VERSION,'scope'=>$scope,'scope_sha256'=>self::digest($scope),'started_at'=>$now,'deadline'=>min($scope['expires_at'],$now+$scope['max_session_seconds']),'reserved_usd_micros'=>$reserved,'request_count'=>0,'revision'=>0,'last_step_at'=>null,'completed'=>[],'events'=>[],'destination_eligibility'=>array_fill_keys($scope['destinations'],'UNKNOWN'),'live_execution_authorized'=>false,'supplier_certified'=>false]);
 }
 /** Public document intents only. Every redirect, frame, worker and subrequest needs separate admission. */
 public static function allows(string $method,string $url,string $resourceType,string $body=''):bool
 {
  if($method!=='GET'||$resourceType!=='document'||$body!==''||strlen($url)>512)return false;
  return preg_match('#^https://printify\.com/(?:app/products/[1-9][0-9]{0,9}/[a-z0-9-]+/[a-z0-9-]+|app/print-provider/[1-9][0-9]{0,9}/[a-z0-9-]+|shipping-rates/[a-z0-9-]+/)$#D',$url)===1;
 }
 public function resume(array $checkpoint,array $expectedScope,int $now,bool $revoked=false):array
 {
  $mac=$checkpoint['seal']??null;unset($checkpoint['seal']);
  if(!is_string($mac)||!hash_equals(hash_hmac('sha256',self::json($checkpoint),$this->checkpointKey),$mac)||$revoked)throw self::denied();
  $this->scope($expectedScope,$now);
  if(($checkpoint['schema']??null)!==self::VERSION||($checkpoint['scope_sha256']??null)!==self::digest($expectedScope)||$checkpoint['scope_sha256']!==self::digest($checkpoint['scope'])||$now<$checkpoint['started_at']||($checkpoint['last_step_at']!==null&&$now<$checkpoint['last_step_at'])||$now>=$checkpoint['deadline']||$checkpoint['live_execution_authorized']!==false||$checkpoint['supplier_certified']!==false)throw self::denied();
  return $this->seal($checkpoint);
 }
 public function step(array $checkpoint,string $operation,string $url,int $now):array
 {
  $s=$this->resume($checkpoint,$checkpoint['scope']??[],$now);
  if(!in_array($operation,['NAVIGATE','EXTRACT'],true)||!self::allows('GET',$url,'document')||$s['request_count']>=$s['scope']['max_requests']||($s['last_step_at']!==null&&$now<=$s['last_step_at']))throw self::denied();
  $hash=self::digest(['scope_sha256'=>$s['scope_sha256'],'operation'=>$operation,'url'=>$url]);
  if(in_array($hash,$s['completed'],true))throw self::denied();
  $recent=array_filter($s['events'],static fn($e)=>$e['observed_at']>$now-60);if(count($recent)>=10)throw self::denied();
  $s['completed'][]=$hash;$s['request_count']++;$s['revision']++;$s['last_step_at']=$now;
  $event=['revision'=>$s['revision'],'operation'=>$operation,'request_sha256'=>$hash,'source_url'=>$url,'observed_at'=>$now,'previous_sha256'=>$s['events']===[]?str_repeat('0',64):$s['events'][array_key_last($s['events'])]['event_sha256']];$event['event_sha256']=self::digest($event);$s['events'][]=$event;
  return $this->seal($s);
 }
 /** Bounded structured synthetic observations only. Raw HTML, screenshots, URLs and profile state are forbidden. */
 public function observation(array $checkpoint,array $expectedScope,array $facts,int $now):array
 {
  $s=$this->resume($checkpoint,$expectedScope,$now);
  self::exact($facts,['family','destination','blueprint_id','provider_id','variant_id','width_px','height_px','dpi','bleed_px','safe_zone_px','price_usd_micros','shipping_usd_micros','eligible']);
  if($s['events']===[]||!in_array($facts['family'],$s['scope']['families'],true)||!in_array($facts['destination'],$s['scope']['destinations'],true))throw self::denied();
  foreach(['blueprint_id','provider_id','variant_id','width_px','height_px','dpi']as $k)if($facts[$k]!==null)self::integer($facts[$k],1,2147483647);
  foreach(['bleed_px','safe_zone_px','price_usd_micros','shipping_usd_micros']as $k)if($facts[$k]!==null)self::integer($facts[$k],0,2147483647);
  if($facts['eligible']!==null&&!is_bool($facts['eligible']))throw self::denied();
  if($facts['destination']==='EU'&&$facts['eligible']!==null)throw self::denied(); // Group research is not destination eligibility.
  $last=$s['events'][array_key_last($s['events'])];if(($last['operation']??null)!=='EXTRACT'||!str_contains($last['source_url'],'/app/products/'))throw self::denied();
  preg_match('#/app/products/([0-9]+)/#',$last['source_url'],$m);if($facts['blueprint_id']!==null&&(string)$facts['blueprint_id']!==$m[1])throw self::denied();
  foreach($facts as &$value)if($value===null)$value='UNKNOWN';unset($value);
  $r=['schema'=>self::VERSION,'classification'=>'OFFLINE_SYNTHETIC_UNREVIEWED','scope_sha256'=>$s['scope_sha256'],'session_sha256'=>$s['scope']['session_sha256'],'request_sha256'=>$last['request_sha256'],'source_url'=>$last['source_url'],'observed_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),'facts'=>$facts,'mockup_rights'=>'UNKNOWN','supplier_certified'=>false,'api_identity_certified'=>false,'live_execution_authorized'=>false];$r['evidence_sha256']=self::digest($r);return $r;
 }
 private function scope(array $s,int $now):void
 {
  self::exact($s,self::FIELDS);self::integer($now,1,PHP_INT_MAX-3600);
  if($s['shop']!=='personalized_pod'||!in_array($s['environment'],['sandbox','test','production'],true)||!is_string($s['provider_shop_id'])||preg_match('/^[1-9][0-9]{0,18}$/D',$s['provider_shop_id'])!==1)throw self::denied();
  foreach(['account_sha256','session_sha256','approval_sha256','scope_revision_sha256','rate_evidence_sha256']as $k)if(!is_string($s[$k])||preg_match('/^[a-f0-9]{64}$/D',$s[$k])!==1)throw self::denied();
  self::integer($s['integration_id'],1,2147483647);self::integer($s['max_requests'],1,100);self::integer($s['max_session_seconds'],1,900);self::integer($s['max_cost_usd_micros'],1,1000000000);self::integer($s['rate_usd_micros_per_minute'],1,100000000);self::integer($s['expires_at'],$now+1,$now+3600);
  foreach(['families','destinations']as $k)if(!is_array($s[$k])||!array_is_list($s[$k])||$s[$k]===[]||count($s[$k])>50||count(array_unique($s[$k],SORT_REGULAR))!==count($s[$k]))throw self::denied();
  foreach($s['families']as $f)if(!is_string($f)||!in_array($f,self::FAMILIES,true))throw self::denied();
  foreach($s['destinations']as $d)if(!is_string($d)||preg_match('/^[A-Z]{2}$/D',$d)!==1)throw self::denied(); // Research labels only, never a supported-country assertion.
 }
 private function seal(array $s):array{unset($s['seal']);$s['seal']=hash_hmac('sha256',self::json($s),$this->checkpointKey);return $s;}
 private static function exact(array $s,array $keys):void{if(count($s)!==count($keys)||array_diff(array_keys($s),$keys))throw self::denied();}
 private static function integer(mixed $v,int $min,int $max):void{if(!is_int($v)||$v<$min||$v>$max)throw self::denied();}
 private static function json(array $v):string{return \DigiForge\Orders\Validator::canonicalJson($v);}
 private static function digest(array $v):string{return hash('sha256',self::json($v));}
 private static function denied():\InvalidArgumentException{return new \InvalidArgumentException('Offline research contract denied. No external action authorized.');}
}
