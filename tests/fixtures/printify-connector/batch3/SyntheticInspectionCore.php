<?php
declare(strict_types=1);
require_once __DIR__.'/../batch2/SyntheticMutationCore.php';
use DigiForgePrintifyConnector\Preparation\{MutationInspectionCoreInterface,MutationIntent};
final class SyntheticInspectionCore implements MutationInspectionCoreInterface {
 public SyntheticMutationCore $core;public string $state='PREPARED';public string $fault='';
 public function __construct(){$this->core=new SyntheticMutationCore();}
 public function version():string{return '1.0.0';}
 public function inspectionVersion():string{return $this->fault==='wrong_version'?'9.0.0':'1.0.0';}
 public function exchange(array $request):array|WP_Error{return $this->core->exchange($request);}
 public function inspect(array $binding):array|WP_Error {
  $state=$this->fault==='unknown'?'UNKNOWN':$this->state;$epoch=hash('sha256','independent-synthetic-epoch-1');
  $journal=['state'=>$this->fault==='unknown_state'?'READY_TO_SEND':$state,'revision'=>1,'epoch'=>$this->fault==='stale'?str_repeat('0',64):$epoch,'approval_state'=>$this->fault==='revoked_approval'?'REVOKED':'VALID','receipt_reference'=>$state==='METADATA_RECONCILED'?str_repeat('a',64):null,'event_count'=>$state==='METADATA_RECONCILED'?1:0,'fence_present'=>$state==='FENCED'];
  $r=['inspection_version'=>'1.0.0','binding'=>$binding,'read_authorized'=>$this->fault!=='read_revoked','observed_at'=>$this->fault==='invalid_timestamp'?0:time(),'checkpoint_epoch'=>$epoch,'journal'=>$this->fault==='missing'?null:$journal,'audit_events'=>$this->fault==='missing'?[]:[['sequence'=>1,'state'=>$state,'occurred_at'=>time(),'event_hash'=>str_repeat('b',64)]]];
  if($r['audit_events']!==[])$r['audit_events'][0]['occurred_at']=$r['observed_at'];
  if($this->fault==='audit_private')$r['audit_events'][0]['body']='private-marker';
  if($this->fault==='wrong_shop')$r['binding']['shop_key']='standard_pod';if($this->fault==='extra_private')$r['private']='private-marker';$r['evidence_hash']=MutationIntent::hash($r);if($this->fault==='tampered_hash')$r['evidence_hash']=str_repeat('0',64);return $r;
 }
}
