<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
/** Companion-side protocol adapter, with no local authority store, sender or credential access. */
final class VersionedMutationHost implements CoreMutationHost {
 public const VERSION='1.0.0';
 public function __construct(private ?MutationCoreInterface $core=null){}
 public static function discover():self {
  try{$class='DigiForge\\Connector\\Printify\\MutationBridge';if(!class_exists($class)||!method_exists($class,'connect'))return new self();$port=$class::connect(self::VERSION);return new self($port instanceof MutationCoreInterface?$port:null);}catch(\Throwable){return new self();}
 }
 public static function binding(MutationIntent $i):array{return ['operation_id'=>$i->operationId,'payload_fingerprint'=>$i->payloadFingerprint,'intent_fingerprint'=>$i->intentFingerprint,'scope_fingerprint'=>$i->scope->fingerprint(),'shop_id'=>$i->scope->shopId,'shop_key'=>$i->scope->shopKey,'integration_id'=>$i->scope->integrationId,'credential_reference'=>$i->scope->credential->integrationId,'provider_shop_id'=>$i->scope->providerShopId,'environment'=>$i->scope->environment,'actor_id'=>$i->context['actor_id'],'authorization_revision'=>$i->context['authorization_revision'],'approval_reference'=>$i->context['approval_reference'],'product_version'=>$i->context['product_version'],'expected_product_version'=>$i->context['expected_product_version']];}
 private function call(string $action,MutationIntent $i,array $data=[]):array|\WP_Error {
  try{
   if($this->core===null||$this->core->version()!==self::VERSION||$i->scope->expiresAt<=time())return self::error();
   if($i->operation==='PRODUCT_PUBLISH'&&(!$this->core instanceof PublishingCoreInterface||$this->core->publicationVersion()!=='1.0.0'||$this->core->authorizePublication(self::binding($i),$i->prepared())!==true))return self::error();
   $request=['contract_version'=>self::VERSION,'action'=>$action,'binding'=>self::binding($i),'context'=>$i->context,'evidence_references'=>$i->evidenceReferences,'duplicate_key'=>$i->duplicateKey,'data'=>$data];
   $r=$this->core->exchange($request);if(is_wp_error($r))return self::error();
   $keys=['contract_version','action','binding','recovery_epoch','checkpoint_epoch','journal_revision','data','journal_hash'];
   if(array_diff(array_keys($r),$keys)||array_diff($keys,array_keys($r))||$r['contract_version']!==self::VERSION||$r['action']!==$action||!is_array($r['binding'])||MutationIntent::hash($r['binding'])!==MutationIntent::hash($request['binding'])||!is_array($r['data'])||!is_int($r['journal_revision'])||$r['journal_revision']<1||!is_string($r['recovery_epoch'])||!preg_match('/^[a-f0-9]{64}$/D',$r['recovery_epoch'])||$r['checkpoint_epoch']!==$r['recovery_epoch'])return self::error();
   $digest=$r['journal_hash'];unset($r['journal_hash']);if(!is_string($digest)||!hash_equals(MutationIntent::hash($r),$digest))return self::error();
   return $r;
  }catch(\Throwable){return self::error();}
 }
 public function authorize(MutationIntent $i):array|\WP_Error {
  $r=$this->call('AUTHORIZE',$i);if(is_wp_error($r))return $r;$a=$r['data'];
  $expected=['operation_id'=>$i->operationId,'payload_fingerprint'=>$i->payloadFingerprint,'intent_fingerprint'=>$i->intentFingerprint,'scope_fingerprint'=>$i->scope->fingerprint(),'actor_id'=>$i->context['actor_id'],'authorization_revision'=>$i->context['authorization_revision'],'approval_reference'=>$i->context['approval_reference'],'current_product_version'=>$i->context['expected_product_version'],'evidence_verified'=>true,'approved'=>true,'stop_all'=>false,'external_lock'=>false];
  if(array_diff(array_keys($a),array_merge(array_keys($expected),['evidence_references','expires_at'])))return self::error();
  foreach($expected as $k=>$v)if(($a[$k]??null)!==$v)return self::error();
  if(($a['evidence_references']??null)!==$i->evidenceReferences||!is_int($a['expires_at']??null)||$a['expires_at']<=time())return self::error();return $a;
 }
 private function journal(string $action,MutationIntent $i,array $data,array $states):array|\WP_Error {
  if(is_wp_error($this->authorize($i)))return self::error();
  $r=$this->call($action,$i,$data);if(is_wp_error($r))return $r;$d=$r['data'];
  if(array_diff(array_keys($d),['state','fence_id','receipt_reference'])||!in_array($d['state']??null,$states,true))return self::error();
  foreach(['fence_id','receipt_reference']as $key)if(isset($d[$key])&&(!is_string($d[$key])||!preg_match('/^[a-f0-9]{64}$/D',$d[$key])))return self::error();
  if($d['state']==='FENCED'&&!isset($d['fence_id']))return self::error();
  if(is_wp_error($this->authorize($i)))return self::error();
  return ['operation_id'=>$i->operationId,'intent_fingerprint'=>$i->intentFingerprint,'state'=>$d['state'],'fence_id'=>$d['fence_id']??null,'receipt_reference'=>$d['receipt_reference']??null,'journal_revision'=>$r['journal_revision'],'recovery_epoch'=>$r['recovery_epoch'],'journal_hash'=>MutationIntent::hash($r),'network_request_permitted'=>false,'retry_permitted'=>false];
 }
 public function register(MutationIntent $i):array|\WP_Error{return $this->journal('REGISTER',$i,['prepared'=>$i->prepared()],['PREPARED']);}
 public function claimOnce(MutationIntent $i,array $a):array|\WP_Error {
  $current=$this->authorize($i);if(is_wp_error($current)||MutationIntent::hash($a)!==MutationIntent::hash($current))return self::error();
  return $this->journal('CLAIM',$i,['authority'=>$current],['FENCED']);
 }
 public function cancel(MutationIntent $i):array|\WP_Error{return $this->journal('CANCEL',$i,[],['CANCELLED','UNKNOWN']);}
 public function recover(MutationIntent $i):array|\WP_Error{return $this->journal('RECOVER',$i,[],['PREPARED','FENCED','UNKNOWN','CANCELLED','METADATA_RECONCILED']);}
 public function acceptReceipt(MutationIntent $i,array $receipt):array|\WP_Error {
  try{$metadata=ProviderReceipt::validate($i,$receipt);$state=$receipt['kind']==='UNKNOWN'?'UNKNOWN':'METADATA_RECONCILED';$r=$this->journal('RECONCILE',$i,['receipt'=>$receipt,'metadata'=>$metadata],[$state]);if(is_wp_error($r)||$r['receipt_reference']!==$receipt['receipt_hash']||$r['recovery_epoch']!==$receipt['recovery_epoch'])return self::error();return $r;}catch(\Throwable){return self::error();}
 }
 public function responseEvidence(MutationIntent $i,string $ref):array|\WP_Error {
  try{if(!preg_match('/^[a-f0-9]{64}$/D',$ref)||is_wp_error($this->authorize($i)))return self::error();$r=$this->call('RECEIPT_READ',$i,['receipt_reference'=>$ref]);if(is_wp_error($r)||array_keys($r['data'])!==['receipt'])return self::error();$e=$r['data']['receipt'];ProviderReceipt::validate($i,$e);if($e['receipt_hash']!==$ref||$e['recovery_epoch']!==$r['recovery_epoch']||$e['kind']!=='RESPONSE'||is_wp_error($this->authorize($i)))return self::error();return ['operation_id'=>$i->operationId,'payload_fingerprint'=>$i->payloadFingerprint,'receipt_reference'=>$ref,'transport_attested'=>true,'http_status'=>$e['http_status'],'body'=>$e['body']];}catch(\Throwable){return self::error();}
 }
 public function inspect(MutationIntent $i):array|\WP_Error{return OperationInspection::read($this->core,$i);}
 public function importReceipt(MutationIntent $i,string $ref):array|\WP_Error {
  try{if(!preg_match('/^[a-f0-9]{64}$/D',$ref)||is_wp_error($this->authorize($i)))return self::error();$r=$this->call('RECEIPT_READ',$i,['receipt_reference'=>$ref]);if(is_wp_error($r)||array_keys($r['data'])!==['receipt'])return self::error();$e=$r['data']['receipt'];ProviderReceipt::validate($i,$e);if($e['receipt_hash']!==$ref||$e['recovery_epoch']!==$r['recovery_epoch'])return self::error();return $this->acceptReceipt($i,$e);}catch(\Throwable){return self::error();}
 }
 public function outcome(MutationIntent $i,string $state,array $receipt):bool {
  if(!in_array($state,['UNKNOWN','METADATA_RECONCILED'],true)||($receipt['operation_id']??null)!==$i->operationId||($receipt['state']??null)!==$state)return false;
  // Core verifies stored provider receipt and current fence atomically. No local success can finalize it.
  $data=['state'=>$state,'receipt_reference'=>$receipt['receipt_reference']??null];$r=$this->journal('OUTCOME',$i,$data,[$state]);return !is_wp_error($r);
 }
 private static function error():\WP_Error{return new \WP_Error('printify_mutation_core_denied','Compatible current DigiForge mutation authority and durable evidence required.',['status'=>409,'network_request_permitted'=>false,'retry_permitted'=>false]);}
}
