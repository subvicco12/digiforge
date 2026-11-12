<?php
declare(strict_types=1);
use DigiForgePrintifyConnector\Preparation\{CoreMutationHost,MutationIntent};
/** Synthetic journal only: not a production durability or authorization implementation. */
final class SyntheticCoreMutationHost implements CoreMutationHost {
 public string $fault='';
 public function __construct(private string $path){}
 private function error():WP_Error{return new WP_Error('synthetic_denied','Synthetic denial');}
 public function authorize(MutationIntent $i):array|WP_Error {
  if(in_array($this->fault,['revoked','revoked_after_claim','audit_unavailable'],true))return $this->error();
  $a=['operation_id'=>$i->operationId,'payload_fingerprint'=>$i->payloadFingerprint,'intent_fingerprint'=>$i->intentFingerprint,'scope_fingerprint'=>$i->scope->fingerprint(),'actor_id'=>$i->context['actor_id'],'authorization_revision'=>$i->context['authorization_revision'],'approval_reference'=>$i->context['approval_reference'],'current_product_version'=>$i->context['expected_product_version'],'evidence_verified'=>true,'approved'=>true,'stop_all'=>false,'external_lock'=>false,'evidence_references'=>$i->evidenceReferences,'expires_at'=>2000000000];
  foreach(['stop_all'=>'stop_all','external_lock'=>'external_lock']as $f=>$k)if($this->fault===$f)$a[$k]=true;
  foreach(['evidence_missing'=>'evidence_verified','stale_revision'=>'authorization_revision','wrong_scope'=>'scope_fingerprint','wrong_actor'=>'actor_id','expired'=>'expires_at','stale_product'=>'current_product_version']as $f=>$k)if($this->fault===$f)$a[$k]=null;
  return $a;
 }
 private function transaction(callable $fn):mixed {
  $lock=fopen($this->path.'.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException();
  try{$data=is_file($this->path)?json_decode(file_get_contents($this->path),true,512,JSON_THROW_ON_ERROR):[];$result=$fn($data);$tmp=$this->path.'.tmp';$file=fopen($tmp,'wb');if(!$file)throw new RuntimeException();chmod($tmp,0600);$bytes=json_encode($data,JSON_THROW_ON_ERROR);if(fwrite($file,$bytes)!==strlen($bytes)||!fflush($file))throw new RuntimeException();if(function_exists('fsync'))fsync($file);fclose($file);if(!rename($tmp,$this->path))throw new RuntimeException();return $result;}finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 public function register(MutationIntent $i):array|WP_Error{return $this->transaction(function(&$d)use($i){foreach($d as $r)if($r['duplicate_key']===$i->duplicateKey)return $this->error();return $d[$i->operationId]=['operation_id'=>$i->operationId,'intent_fingerprint'=>$i->intentFingerprint,'duplicate_key'=>$i->duplicateKey,'state'=>'PREPARED'];});}
 public function claimOnce(MutationIntent $i,array $authority):array|WP_Error {
  if($this->fault==='claim_write_failure')return $this->error();
  $r=$this->transaction(function(&$d)use($i){if(($d[$i->operationId]['state']??null)!=='PREPARED')return $this->error();$d[$i->operationId]['state']='FENCED';$d[$i->operationId]['fence_id']=hash('sha256',$i->operationId.':synthetic');return $d[$i->operationId];});return $this->fault==='lost_claim_ack'?$this->error():$r;
 }
 public function responseEvidence(MutationIntent $i,string $ref):array|WP_Error {
  if($this->state($i)!=='FENCED'||$this->fault==='timeout')return $this->error();$a=$i->prepared()['asset'];
  return ['operation_id'=>$i->operationId,'payload_fingerprint'=>$i->payloadFingerprint,'receipt_reference'=>$ref,'transport_attested'=>true,'http_status'=>$this->fault==='rate_limit'?429:200,'body'=>$this->fault==='malformed'?[]:['id'=>'synthetic_image','file_name'=>$a['sha256'].'.png','width'=>$a['width_px'],'height'=>$a['height_px']]];
 }
 public function outcome(MutationIntent $i,string $state,array $receipt):bool{return $this->transaction(function(&$d)use($i,$state,$receipt){if(($d[$i->operationId]['state']??null)!=='FENCED')return false;$d[$i->operationId]['state']=$state;$d[$i->operationId]['receipt']=$receipt;return true;});}
 public function recordCount():int{return $this->transaction(static fn(&$d)=>count($d));}
 public function state(MutationIntent $i):?string{return $this->transaction(static fn(&$d)=>$d[$i->operationId]['state']??null);}
}
