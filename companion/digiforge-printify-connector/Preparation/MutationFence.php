<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
/** No sender exists here. All journal/fence/response authority remains behind the DigiForge-owned port. */
final class MutationFence {
 public function __construct(private CoreMutationHost $host=new UnavailableMutationHost()){}
 private function authority(MutationIntent $i):array|\WP_Error {
  try{$a=$this->host->authorize($i);if(is_wp_error($a))return $a;
   foreach(['operation_id'=>$i->operationId,'payload_fingerprint'=>$i->payloadFingerprint,'intent_fingerprint'=>$i->intentFingerprint,'scope_fingerprint'=>$i->scope->fingerprint(),'actor_id'=>$i->context['actor_id'],'authorization_revision'=>$i->context['authorization_revision'],'approval_reference'=>$i->context['approval_reference'],'current_product_version'=>$i->context['expected_product_version'],'evidence_verified'=>true,'approved'=>true,'stop_all'=>false,'external_lock'=>false]as $key=>$value)if(($a[$key]??null)!==$value)return self::error();
   $refs=$a['evidence_references']??null;if(!is_array($refs))return self::error();sort($refs);if($refs!==$i->evidenceReferences||!is_int($a['expires_at']??null)||$a['expires_at']<=time()||$i->scope->expiresAt<=time())return self::error();return $a;
  }catch(\Throwable){return self::error();}
 }
 public function register(MutationIntent $i):array|\WP_Error {
  $a=$this->authority($i);if(is_wp_error($a))return $a;
  try{$r=$this->host->register($i);if(is_wp_error($r))return $r;if(($r['operation_id']??null)!==$i->operationId||($r['intent_fingerprint']??null)!==$i->intentFingerprint||($r['state']??null)!=='PREPARED')return self::error();$current=$this->authority($i);if(is_wp_error($current)||MutationIntent::hash($current)!==MutationIntent::hash($a))return self::error();return ['operation_id'=>$i->operationId,'intent_fingerprint'=>$i->intentFingerprint,'state'=>'PREPARED','network_request_permitted'=>false];}catch(\Throwable){return self::error();}
 }
 public function reserve(MutationIntent $i):array|\WP_Error {
  $a=$this->authority($i);if(is_wp_error($a))return $a;
  try{$r=$this->host->claimOnce($i,$a);if(is_wp_error($r))return $r;
   if(($r['operation_id']??null)!==$i->operationId||($r['intent_fingerprint']??null)!==$i->intentFingerprint||($r['state']??null)!=='FENCED'||!is_string($r['fence_id']??null)||!preg_match('/^[a-f0-9]{64}$/D',$r['fence_id'])){ $this->unknown($i);return self::error(); }
   $now=$this->authority($i);if(is_wp_error($now)||MutationIntent::hash($now)!==MutationIntent::hash($a)){$this->unknown($i);return self::error();}
   return ['operation_id'=>$i->operationId,'fence_id'=>$r['fence_id'],'state'=>'FENCED','network_request_permitted'=>false,'future_core_final_sender_required'=>true];
  }catch(\Throwable){$this->unknown($i);return self::error();}
 }
 public function reconcile(MutationIntent $i,string $receiptReference):array {
  $unknown=['operation_id'=>$i->operationId,'state'=>'UNKNOWN','retry_permitted'=>false,'network_request_permitted'=>false];
  try{
   if(!preg_match('/^[a-f0-9]{64}$/D',$receiptReference)||is_wp_error($this->authority($i)))throw new \RuntimeException();
   $e=$this->host->responseEvidence($i,$receiptReference);
   if(is_wp_error($e)||($e['operation_id']??null)!==$i->operationId||($e['payload_fingerprint']??null)!==$i->payloadFingerprint||($e['receipt_reference']??null)!==$receiptReference||($e['transport_attested']??null)!==true||($e['http_status']??null)!==200||!is_array($e['body']??null))throw new \RuntimeException();
   $metadata=$i->operation==='PRODUCT_PUBLISH'?PublicationContract::reconcile($i->prepared(),$e['body'],200):($i->operation==='IMAGE_UPLOAD'?UploadContract::reconcile($i->prepared(),$e['body'],200):ProductDraftContract::reconcile($i->prepared(),$e['body'],200));
   if(is_wp_error($this->authority($i)))throw new \RuntimeException();
   $out=['operation_id'=>$i->operationId,'state'=>'METADATA_RECONCILED','receipt_reference'=>$receiptReference,'metadata'=>$metadata,'retry_permitted'=>false,'network_request_permitted'=>false];
   if(!$this->host->outcome($i,'METADATA_RECONCILED',$out)||is_wp_error($this->authority($i)))throw new \RuntimeException();return $out;
  }catch(\Throwable){$this->unknown($i);return $unknown;}
 }
 private function unknown(MutationIntent $i):void{try{$this->host->outcome($i,'UNKNOWN',['operation_id'=>$i->operationId,'state'=>'UNKNOWN','retry_permitted'=>false,'network_request_permitted'=>false]);}catch(\Throwable){}}
 private static function error():\WP_Error{return new \WP_Error('printify_mutation_preparation_denied','Current exact DigiForge evidence and durable one-shot authority are required.',['status'=>409,'network_request_permitted'=>false,'retry_permitted'=>false]);}
}
