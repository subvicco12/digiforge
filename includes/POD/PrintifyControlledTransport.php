<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;
/** No-retry controlled Printify mutation transport. */
final class PrintifyControlledTransport{
 private $sender;public function __construct(?callable $sender=null){$this->sender=$sender??static fn(string $u,array $a)=>wp_remote_request($u,$a);}
 public function executeProduct(string $planHash,string $authorizationHash):array|WP_Error{return (new PrintifyProductPipeline())->execute($planHash,$authorizationHash,$this->sender);}
 public function executeLifecycle(string $planHash,string $authorizationHash):array|WP_Error{return (new PrintifyLifecycleWorkflow())->execute($planHash,$authorizationHash,$this->sender);}
 public function executeApiRead(string $planHash,string $authorizationHash):array|WP_Error{return (new PrintifyApiReadWorkflow())->execute($planHash,$authorizationHash,$this->sender);}
 public function execute(array $permit,array $payload):array|WP_Error{
  $request=PrintifyMutationRequest::prepare($permit,$payload);if(is_wp_error($request))return $request;
  $approvedFingerprint=strtolower(trim((string)($permit['request_fingerprint']??'')));
  if(!preg_match('/^[a-f0-9]{64}$/',$approvedFingerprint)||!hash_equals($approvedFingerprint,(string)$request['request_fingerprint']))return new WP_Error('digiforge_printify_request_binding','Current Printify mutation does not match authorization-bound approved request evidence.',['status'=>409]);
  $authorized=PrintifyLiveTransportInterlock::authorize($permit,$request);if(is_wp_error($authorized))return $authorized;
  // Recheck immediately before credential retrieval/network.
  $fresh=PrintifyLiveTransportInterlock::authorize($permit,$request);if(is_wp_error($fresh))return $fresh;
  $claimed=PrintifyDispatchClaim::claim($permit,$request);if(is_wp_error($claimed))return $claimed;
  $identity=(string)($request['reconciliation_identity']['external_reference']??'');$intent=PrintifyOperationClaim::claim($request['connection_scope'],$request['action'],$identity,$request['request_fingerprint']);if(is_wp_error($intent))return $intent;
  $sender=$this->sender;$attempted=false;
  $out=(new PrintifyScopedCredentialRetriever())->consume((int)$request['integration_id'],static function(string $token)use($sender,$request,$permit,&$attempted){
   $args=['method'=>'POST','headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json;charset=utf-8','Accept'=>'application/json','User-Agent'=>'DigiForge/PrintifyApi'],'timeout'=>20,'redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>true,'limit_response_size'=>1048577];
   if($request['body']!==[])$args['body']=wp_json_encode($request['body']);
   $fresh=PrintifyLiveTransportInterlock::authorize($permit,$request);if(is_wp_error($fresh))return $fresh;
   $quotaScope=is_array($request['connection_scope'])?$request['connection_scope']:[];$correlation='mutation:'.$permit['authorization_hash'];$quota=PrintifyAccountQuota::reserve($quotaScope,'/v1'.$request['endpoint'],'POST',$correlation);if(is_wp_error($quota))return $quota;
   $attempted=true;try{$response=$sender('https://api.printify.com/v1'.$request['endpoint'],$args);}catch(\Throwable){PrintifyAccountQuota::observe($quotaScope,0,[],$correlation);$token='';return self::unknown($permit,$request,'transport_exception');}$token='';
   $quotaObserved=PrintifyAccountQuota::observe($quotaScope,is_wp_error($response)?0:(int)wp_remote_retrieve_response_code($response),is_wp_error($response)?[]:['retry-after'=>(string)wp_remote_retrieve_header($response,'retry-after')],$correlation);if(is_wp_error($quotaObserved))return self::unknown($permit,$request,'quota_evidence_unknown');
   if(is_wp_error($response))return self::unknown($permit,$request,'transport_error');
   $fresh=PrintifyLiveTransportInterlock::authorize($permit,$request);if(is_wp_error($fresh))return new WP_Error('printify_connection_dispatch_unknown','Ownership changed after dispatch; reconciliation is required.',['status'=>409,'network_request_attempted'=>true,'request_fingerprint'=>$request['request_fingerprint'],'reconciliation_identity'=>$request['reconciliation_identity']]);
   $status=(int)wp_remote_retrieve_response_code($response);
   if($status>=200&&$status<300){
    try{$external=self::acknowledgedId((string)wp_remote_retrieve_body($response),(string)$request['existing_external_reference']);}catch(\Throwable){return self::unknown($permit,$request,'invalid_order_acknowledgement');}
    return ['status'=>'SUCCEEDED','action'=>$permit['action'],'authorization_hash'=>$permit['authorization_hash'],'evidence_hash'=>$permit['evidence_hash'],'external_reference'=>$external];
   }
   if(in_array($status,[400,401,403,404,422],true))return ['status'=>'FAILED','action'=>$permit['action'],'authorization_hash'=>$permit['authorization_hash'],'evidence_hash'=>$permit['evidence_hash'],'failure_category'=>'PROVIDER_REJECTED','failure_code'=>'http_'.$status];
   return ['status'=>'UNKNOWN','action'=>$permit['action'],'authorization_hash'=>$permit['authorization_hash'],'evidence_hash'=>$permit['evidence_hash'],'failure_category'=>'AMBIGUOUS_HTTP','failure_code'=>'http_'.$status,'request_fingerprint'=>$request['request_fingerprint'],'reconciliation_identity'=>$request['reconciliation_identity']];
  },is_array($request['connection_scope'])?$request['connection_scope']:[]);
  // A post-dispatch credential/redaction/revocation error cannot erase pinned recovery identity.
  if(is_wp_error($out)&&$attempted&&($out->get_error_data()['network_request_attempted']??false)!==true)return self::unknown($permit,$request,'post_dispatch_evidence_changed');
  return $out;
 }
 /** Both create and production acknowledgements must carry the documented order id. */
 public static function acknowledgedId(string $raw,string $expected=''):string{
  if(strlen($raw)>1048576)throw new \LengthException('Unbounded acknowledgement.');
  $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
  if(!is_array($data)||!is_string($data['id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$data['id'])||($expected!==''&&!hash_equals($expected,$data['id'])))throw new \InvalidArgumentException('Order acknowledgement mismatch.');
  return $data['id'];
 }
 /** Retain only pinned recovery evidence; provider error text may contain protected material. */
 private static function unknown(array $permit,array $request,string $code):array{
  return ['status'=>'UNKNOWN','action'=>$permit['action'],'authorization_hash'=>$permit['authorization_hash'],'evidence_hash'=>$permit['evidence_hash'],'failure_category'=>'AMBIGUOUS_TRANSPORT','failure_code'=>$code,'request_fingerprint'=>$request['request_fingerprint'],'reconciliation_identity'=>$request['reconciliation_identity'],'retry_permitted'=>false,'reconciliation_required'=>true];
 }
}
