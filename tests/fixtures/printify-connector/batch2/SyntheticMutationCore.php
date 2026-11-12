<?php
declare(strict_types=1);
use DigiForgePrintifyConnector\Preparation\{MutationCoreInterface,VersionedMutationHost,MutationIntent,ProviderReceipt};
/** Synthetic authoritative-core protocol fixture; no real provider, credential or hosted durability. */
final class SyntheticMutationCore implements MutationCoreInterface {
 public string $version='1.0.0';public string $fault='';public int $calls=0;public int $receiptCount=0;public array $records=[];public array $receipts=[];
 private string $epoch;private int $revision=1;
 public function __construct(){$this->epoch=hash('sha256','independent-synthetic-epoch-1');}
 public function exportState():array{return ['records'=>$this->records,'receipts'=>$this->receipts,'receipt_count'=>$this->receiptCount,'revision'=>$this->revision];}
 public function importState(array $state):void{$this->records=$state['records'];$this->receipts=$state['receipts'];$this->receiptCount=$state['receipt_count'];$this->revision=$state['revision'];}
 public function version():string{return $this->version;}
 public function advanceEpoch():void{$this->epoch=hash('sha256',$this->epoch);}
 private function deny():WP_Error{return new WP_Error('synthetic_core_denied','Synthetic core denial');}
 private function envelope(array $q,array $data):array {
  $r=['contract_version'=>'1.0.0','action'=>$q['action'],'binding'=>$q['binding'],'recovery_epoch'=>$this->epoch,'checkpoint_epoch'=>$this->fault==='epoch_mismatch'?str_repeat('0',64):$this->epoch,'journal_revision'=>$this->revision,'data'=>$data];
  if($this->fault==='wrong_credential')$r['binding']['credential_reference']++;
  if($this->fault==='wrong_shop')$r['binding']['shop_key']='standard_pod';
  $r['journal_hash']=MutationIntent::hash($r);if($this->fault==='journal_hash')$r['journal_hash']=str_repeat('0',64);if($this->fault==='unexpected_output')$r['unexpected']='private-value';return $r;
 }
 public function exchange(array $q):array|WP_Error {
  $this->calls++;$b=$q['binding'];$id=$b['operation_id'];$action=$q['action'];
  if($this->fault==='revoked'||$this->fault==='audit_unavailable')return $this->deny();
  if(isset($this->records[$id])&&($this->records[$id]['epoch']!==$this->epoch||$this->records[$id]['binding']!==$b))return $this->deny();
  if($action==='AUTHORIZE'){
   $a=['operation_id'=>$id,'payload_fingerprint'=>$b['payload_fingerprint'],'intent_fingerprint'=>$b['intent_fingerprint'],'scope_fingerprint'=>$b['scope_fingerprint'],'actor_id'=>$b['actor_id'],'authorization_revision'=>$b['authorization_revision'],'approval_reference'=>$b['approval_reference'],'current_product_version'=>$b['expected_product_version'],'evidence_verified'=>true,'approved'=>true,'stop_all'=>$this->fault==='stop_all','external_lock'=>$this->fault==='external_lock','evidence_references'=>$q['evidence_references'],'expires_at'=>$this->fault==='expired'?1:2000000000];
   if($this->fault==='stale_approval')$a['approval_reference']=str_repeat('0',64);if($this->fault==='stale_version')$a['current_product_version']++;
   return $this->envelope($q,$a);
  }
  if($action==='REGISTER'){
   foreach($this->records as $record)if($record['duplicate_key']===$q['duplicate_key'])return $this->deny();
   $this->records[$id]=['binding'=>$b,'duplicate_key'=>$q['duplicate_key'],'state'=>'PREPARED','epoch'=>$this->epoch,'sequence'=>0,'receipt_reference'=>null];$this->revision++;return $this->envelope($q,['state'=>'PREPARED']);
  }
  if(!isset($this->records[$id]))return $this->deny();$record=&$this->records[$id];
  if($action==='CLAIM'){
   if($record['state']!=='PREPARED')return $this->deny();$record['state']='FENCED';$record['fence_id']=hash('sha256',$id.':synthetic-fence');$this->revision++;
   $response=$this->fault==='lost_claim_ack'?$this->deny():$this->envelope($q,['state'=>'FENCED','fence_id'=>$record['fence_id']]);if($this->fault==='revoke_after_claim')$this->fault='revoked';return $response;
  }
  if($action==='RECOVER')return $this->envelope($q,$this->journal($record));
  if($action==='CANCEL'){
   if($record['state']==='PREPARED')$record['state']='CANCELLED';elseif(in_array($record['state'],['FENCED','UNKNOWN'],true))$record['state']='UNKNOWN';else return $this->deny();$this->revision++;return $this->envelope($q,$this->journal($record));
  }
  if($action==='RECEIPT_READ'){
   $r=$this->receipts[$q['data']['receipt_reference']]??null;if(!is_array($r)||!in_array($record['state'],['FENCED','UNKNOWN','METADATA_RECONCILED'],true)||$r['fence_id']!==$record['fence_id'])return $this->deny();return $this->envelope($q,['receipt'=>$r]);
  }
  if($action==='RECONCILE'||$action==='OUTCOME'){
   if($action==='OUTCOME'&&$q['data']['state']==='UNKNOWN'){
    if(!in_array($record['state'],['FENCED','UNKNOWN'],true))return $this->deny();$record['state']='UNKNOWN';$this->revision++;return $this->envelope($q,$this->journal($record));
   }
   $r=$action==='RECONCILE'?$q['data']['receipt']:($this->receipts[$q['data']['receipt_reference']]??null);if(!is_array($r))return $this->deny();
   if($record['receipt_reference']===$r['receipt_hash'])return $this->envelope($q,$this->journal($record));
   if(!in_array($record['state'],['FENCED','UNKNOWN'],true)||$r['fence_id']!==$record['fence_id']||$r['recovery_epoch']!==$this->epoch||$r['sequence']!==$record['sequence']+1)return $this->deny();
   foreach($this->receipts as $other)if($other['event_id']===$r['event_id']&&$other['receipt_hash']!==$r['receipt_hash'])return $this->deny();
   $record['receipt_reference']=$r['receipt_hash'];$record['sequence']=$r['sequence'];$record['state']=$r['kind']==='UNKNOWN'?'UNKNOWN':'METADATA_RECONCILED';$this->receipts[$r['receipt_hash']]=$r;$this->receiptCount++;$this->revision++;return $this->envelope($q,$this->journal($record));
  }
  return $this->deny();
 }
 private function journal(array $r):array{return ['state'=>$r['state']]+(isset($r['fence_id'])?['fence_id'=>$r['fence_id']]:[])+($r['receipt_reference']!==null?['receipt_reference'=>$r['receipt_reference']]:[]);}
 public function receipt(MutationIntent $i):array {
  $a=$i->prepared()['asset']??null;$e=$i->prepared()['evidence']??[];$body=$i->operation==='IMAGE_UPLOAD'?['id'=>'synthetic_image','file_name'=>$a['sha256'].'.png','width'=>$a['width_px'],'height'=>$a['height_px']]:['id'=>$e['current_product_id']??'synthetic_product','shop_id'=>(int)$i->scope->providerShopId,'blueprint_id'=>$e['blueprint_id'],'print_provider_id'=>$e['print_provider_id'],'variants'=>array_map(static fn($v)=>['id'=>$v['id']],$i->prepared()['core_request']['body']['variants']),'visible'=>false,'is_locked'=>false];$r=['schema'=>'DF_PRINTIFY_RECEIPT_1','operation_id'=>$i->operationId,'payload_fingerprint'=>$i->payloadFingerprint,'intent_fingerprint'=>$i->intentFingerprint,'scope_fingerprint'=>$i->scope->fingerprint(),'shop_key'=>$i->scope->shopKey,'integration_id'=>$i->scope->integrationId,'credential_reference'=>$i->scope->credential->integrationId,'provider_shop_id'=>$i->scope->providerShopId,'approval_reference'=>$i->context['approval_reference'],'authorization_revision'=>$i->context['authorization_revision'],'product_version'=>$i->context['product_version'],'event_id'=>hash('sha256',$i->operationId.':synthetic-response'),'sequence'=>1,'kind'=>'RESPONSE','fence_id'=>hash('sha256',$i->operationId.':synthetic-fence'),'recovery_epoch'=>$this->epoch,'http_status'=>200,'sent_at'=>time()-2,'received_at'=>time()-1,'body'=>$body];$r['receipt_hash']=ProviderReceipt::hash($r);return $r;
 }
}
