<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;
/** No-retry controlled Printify mutation transport. */
final class PrintifyControlledTransport{
 private $sender;public function __construct(?callable $sender=null){$this->sender=$sender??static fn(string $u,array $a)=>wp_remote_request($u,$a);}
 public function execute(array $permit,array $payload):array|WP_Error{
  $request=PrintifyMutationRequest::prepare($permit,$payload);if(is_wp_error($request))return $request;
  $approvedFingerprint=strtolower(trim((string)($permit['request_fingerprint']??'')));
  if(!preg_match('/^[a-f0-9]{64}$/',$approvedFingerprint)||!hash_equals($approvedFingerprint,(string)$request['request_fingerprint']))return new WP_Error('digiforge_printify_request_binding','Current Printify mutation does not match authorization-bound approved request evidence.',['status'=>409]);
  $authorized=PrintifyLiveTransportInterlock::authorize($permit,$request);if(is_wp_error($authorized))return $authorized;
  // Recheck immediately before credential retrieval/network.
  $fresh=PrintifyLiveTransportInterlock::authorize($permit,$request);if(is_wp_error($fresh))return $fresh;
  $sender=$this->sender;
  return (new PrintifyScopedCredentialRetriever())->consume((int)$request['integration_id'],static function(string $token)use($sender,$request,$permit){
   $args=['method'=>'POST','headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json;charset=utf-8','Accept'=>'application/json'],'timeout'=>20,'redirection'=>0,'sslverify'=>true];
   if($request['body']!==[])$args['body']=wp_json_encode($request['body']);
   $response=$sender('https://api.printify.com/v1'.$request['endpoint'],$args);$token='';
   if(is_wp_error($response)){$response->add_data(['network_request_attempted'=>true,'request_fingerprint'=>$request['request_fingerprint'],'reconciliation_identity'=>$request['reconciliation_identity']]);return $response;}
   $status=(int)wp_remote_retrieve_response_code($response);
   if($status>=200&&$status<300){
    $external=(string)$request['existing_external_reference'];
    if($external===''){$decoded=json_decode((string)wp_remote_retrieve_body($response),true);$external=is_array($decoded)?trim((string)($decoded['id']??'')):'';}
    if($external==='')return ['status'=>'UNKNOWN','action'=>$permit['action'],'authorization_hash'=>$permit['authorization_hash'],'evidence_hash'=>$permit['evidence_hash'],'failure_category'=>'RESPONSE_PROCESSING','failure_code'=>'missing_external_reference','request_fingerprint'=>$request['request_fingerprint'],'reconciliation_identity'=>$request['reconciliation_identity']];
    return ['status'=>'SUCCEEDED','action'=>$permit['action'],'authorization_hash'=>$permit['authorization_hash'],'evidence_hash'=>$permit['evidence_hash'],'external_reference'=>$external];
   }
   if(in_array($status,[400,401,403,404,422],true))return ['status'=>'FAILED','action'=>$permit['action'],'authorization_hash'=>$permit['authorization_hash'],'evidence_hash'=>$permit['evidence_hash'],'failure_category'=>'PROVIDER_REJECTED','failure_code'=>'http_'.$status];
   return ['status'=>'UNKNOWN','action'=>$permit['action'],'authorization_hash'=>$permit['authorization_hash'],'evidence_hash'=>$permit['evidence_hash'],'failure_category'=>'AMBIGUOUS_HTTP','failure_code'=>'http_'.$status,'request_fingerprint'=>$request['request_fingerprint'],'reconciliation_identity'=>$request['reconciliation_identity']];
  });
 }
}
