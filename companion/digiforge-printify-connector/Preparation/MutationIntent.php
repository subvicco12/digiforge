<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
use DigiForge\Connector\Printify\ShopBinding;
/** Immutable inert request: fingerprints detect drift; they NEVER supply authority. */
final readonly class MutationIntent implements \JsonSerializable {
 public string $operationId;public string $payloadFingerprint;public string $intentFingerprint;public string $duplicateKey;public array $evidenceReferences;
 private array $prepared;public array $context;
 public function __construct(public string $operation,public ShopBinding $scope,array $prepared,array $context){
  // Prepared requests are JSON data, never executable PHP objects/callbacks.
  if(!self::jsonData($prepared,0))self::bad();
  $prepared=self::detached($prepared);
  $keys=['actor_id','logical_reference','approval_reference','authorization_revision','product_version','expected_product_version'];
  if(array_diff($keys,array_keys($context))||array_diff(array_keys($context),$keys))self::bad();
  foreach(['actor_id','authorization_revision'] as $k)if(!is_int($context[$k])||$context[$k]<1)self::bad();
  foreach(['product_version','expected_product_version'] as $k)if(!is_int($context[$k])||$context[$k]<0)self::bad();
  if($context['authorization_revision']!==$scope->revision||!is_string($context['logical_reference'])||!preg_match('/^[A-Za-z0-9_.:-]{1,160}$/D',$context['logical_reference'])||!is_string($context['approval_reference'])||!preg_match('/^[a-f0-9]{64}$/D',$context['approval_reference']))self::bad();
  if($scope->shopKey!=='personalized_pod')self::bad();
  $context=self::detached($context);$this->context=$context;
  $refs=[$context['approval_reference']];
  if($operation==='IMAGE_UPLOAD'){
   $body=$prepared['body']??[];$bytes=is_string($body['contents']??null)?base64_decode($body['contents'],true):false;if(!is_string($bytes)||!is_array($prepared['asset']??null))self::bad();
   if(array_key_exists('core_wire_descriptor',$prepared)){
    if(!is_array($prepared['core_wire_descriptor']))self::bad();
    $expected=UploadContract::prepareFromCoreDescriptor($scope,$prepared['asset'],$bytes,$prepared['core_wire_descriptor'],$context['actor_id']);
   }else $expected=UploadContract::prepare($scope,$prepared['asset'],$bytes);
   if(self::hash($expected)!==self::hash($prepared))self::bad();
   $wire=['method'=>$expected['method'],'endpoint'=>$expected['path'],'body'=>$expected['body']];
   $logical=$expected['asset']['sha256'];foreach(['rights_receipt_sha256','ownership_receipt_sha256','geometry_receipt_sha256']as $k)$refs[]=$expected['asset'][$k];
   if(isset($expected['core_wire_descriptor']))foreach(['source_evidence_hash','plan_hash','package_hash','descriptor_hash']as $k)$refs[]=$expected['core_wire_descriptor'][$k];
  }elseif($operation==='PRODUCT_PUBLISH'){
   $e=$prepared['evidence']??[];$flags=$prepared['request']['body']??[];if(!is_array($e)||!is_array($flags))self::bad();
   $expected=PublicationContract::prepare($scope,['product_id'=>$e['current_product_id']??null,'flags'=>$flags],$e);if(self::hash($expected)!==self::hash($prepared))self::bad();
   foreach(['authorization_revision','product_version','expected_product_version']as $k)if(($e[$k]??null)!==$context[$k])self::bad();if($e['approval_receipt']!==$context['approval_reference'])self::bad();
   $wire=$expected['request'];$logical=$e['current_product_id'].':'.$e['product_version'];foreach(['channel_receipt','product_receipt','approval_receipt']as $k)$refs[]=$e[$k];
  }elseif(in_array($operation,['PRODUCT_CREATE','PRODUCT_UPDATE'],true)){
   $wire=$prepared['core_request']??[];$e=$prepared['evidence']??[];if(!is_array($wire)||!is_array($e))self::bad();$input=$wire['body']??[];if(!is_array($input))self::bad();
   if($operation==='PRODUCT_UPDATE'){$input['product_id']=$e['current_product_id']??null;$input['known_variant_ids']=array_column($input['variants']??[],'id');$input['blueprint_id']=$e['blueprint_id']??null;$input['print_provider_id']=$e['print_provider_id']??null;}
   $expected=ProductDraftContract::prepare($operation,$scope,$input,$e);if(self::hash($expected)!==self::hash($prepared))self::bad();
   foreach(['authorization_revision','product_version','expected_product_version']as $k)if(($e[$k]??null)!==$context[$k])self::bad();
   if(($e['approval_receipt_sha256']??null)!==$context['approval_reference'])self::bad();
   $wire=['method'=>$wire['method'],'endpoint'=>$wire['endpoint'],'body'=>$wire['body']];$logical=$context['logical_reference'].':'.$context['product_version'];
   foreach(['catalog_receipt_sha256','geometry_receipt_sha256','approval_receipt_sha256']as $k)$refs[]=$e[$k];foreach($e['owned_uploads']as $row)$refs[]=$row['upload_receipt_sha256'];
  }else self::bad();
  foreach($refs as $ref)if(!is_string($ref)||!preg_match('/^[a-f0-9]{64}$/D',$ref))self::bad();$refs=array_values(array_unique($refs));sort($refs);$this->evidenceReferences=$refs;
  $this->prepared=$prepared;$this->payloadFingerprint=self::hash($wire);
  // Physical scope and logical entity survive permission revision changes: no fresh duplicate write.
  $this->duplicateKey=self::hash([$operation,$scope->shopKey,$scope->integrationId,$scope->providerShopId,$scope->environment,$logical]);
  $this->operationId=self::hash(['DF_PRINTIFY_PREPARATION_V1',$this->duplicateKey,$this->payloadFingerprint]);
  $this->intentFingerprint=self::hash([$this->operationId,$scope->fingerprint(),$context,$prepared]);
 }
 public function prepared():array{return $this->prepared;}
 public static function hash(array $a):string{if(!self::jsonData($a,0))self::bad();$sort=static function(array $v)use(&$sort):array{$r=[];foreach($v as $key=>$x)$r[$key]=is_array($x)?$sort($x):$x;if(!array_is_list($r))ksort($r);return $r;};return hash('sha256',json_encode($sort($a),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));}
 public function jsonSerialize():array{return ['operation_id'=>$this->operationId,'operation'=>$this->operation,'payload_fingerprint'=>$this->payloadFingerprint,'intent_fingerprint'=>$this->intentFingerprint,'scope_fingerprint'=>$this->scope->fingerprint(),'credential_reference_integration_id'=>$this->scope->credential->integrationId,'authorization_revision'=>$this->context['authorization_revision'],'product_version'=>$this->context['product_version'],'expected_product_version'=>$this->context['expected_product_version'],'core_evidence_verification_required'=>true,'durable_core_journal_required'=>true,'network_request_permitted'=>false,'external_execution_authorized'=>false];}
 private static function jsonData(mixed $v,int $depth):bool {
  if($depth>32)return false;if(is_array($v)){foreach($v as $child)if(!self::jsonData($child,$depth+1))return false;return true;}
  return $v===null||is_string($v)||is_bool($v)||is_int($v)||(is_float($v)&&is_finite($v));
 }
 private static function detached(array $value):array{$copy=[];foreach($value as $key=>$child)$copy[$key]=is_array($child)?self::detached($child):$child;return $copy;}
 private static function bad():never{throw new \InvalidArgumentException('Exact inert mutation request, core identity and reviewed revision references required.');}
}
