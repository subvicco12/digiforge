<?php
declare(strict_types=1);
use DigiForgePrintifyConnector\Preparation\{WorkflowCoreInterface,MutationIntent};
use DigiForge\Connector\Printify\ShopBinding;
/** Synthetic authoritative core fixture only. No live secret, provider sender or production store. */
final class SyntheticWorkflowCore implements WorkflowCoreInterface {
 public string $fault='';public array $records=[];private array $watermarks=[];private array $history=[];private int $revision=0;
 public function exportState():array{return ['records'=>$this->records,'watermarks'=>$this->watermarks,'history'=>$this->history,'revision'=>$this->revision];}
 public function importState(array $s):void{$this->records=$s['records'];$this->watermarks=$s['watermarks'];$this->history=$s['history'];$this->revision=$s['revision'];}
 public function version():string{return '1.0.0';}public function workflowVersion():string{return $this->fault==='wrong_version'?'9.0.0':'1.0.0';}
 public function exchange(array $q):array|WP_Error{return new WP_Error('no_dispatch');}
 public function authorizeWorkflow(ShopBinding $s,int $actor,string $action):array|WP_Error {if($this->fault==='revoked')return new WP_Error('synthetic_revoked');return ['scope_fingerprint'=>$this->fault==='wrong_shop'?str_repeat('0',64):$s->fingerprint(),'actor_id'=>$actor,'action'=>$action,'authorization_revision'=>$s->revision,'local_evidence_authorized'=>true,'external_writes_denied'=>true,'expires_at'=>time()+300];}
 public function withWebhookSecret(ShopBinding $s,callable $verify):mixed{return $verify(hash('sha256','synthetic-only-signing-fixture'));}
 public function recordWorkflow(array $q):array|WP_Error {
  if($this->fault==='storage_failure')return new WP_Error('synthetic_outage');$e=$q['evidence'];$kind=$q['kind'];$id=$kind==='ORDER_OBSERVATION'?($e['external_identity']??$e['provider_identity']):$e['dedupe_key'];$content=$kind==='WEBHOOK_EVIDENCE'?$e['payload_hash']:$e['content_hash'];$state='RECORDED';
  if(isset($this->records[$id]))$state=$this->records[$id]['content_hash']===$content?'DUPLICATE':'CONFLICT';
  elseif($kind==='WEBHOOK_EVIDENCE'&&isset($this->watermarks[$e['resource_key']])&&$e['created_at']<=$this->watermarks[$e['resource_key']])$state='OUT_OF_ORDER';
  elseif($kind==='WEBHOOK_REJECTION')$state='REJECTED';
  if($state==='RECORDED'){$this->records[$id]=['content_hash'=>$content,'kind'=>$kind];if($kind==='WEBHOOK_EVIDENCE')$this->watermarks[$e['resource_key']]=$e['created_at'];}
  $reason=$kind==='ORDER_OBSERVATION'?'ORDER_REVIEW_REQUIRED':($kind==='WEBHOOK_REJECTION'?'WEBHOOK_REJECTED':'AUTHORITATIVE_READBACK_REQUIRED');if($state==='CONFLICT')$reason='EVENT_CONFLICT';if($state==='OUT_OF_ORDER')$reason='EVENT_OUT_OF_ORDER';$this->history[]=['scope_fingerprint'=>$q['scope_fingerprint'],'kind'=>$kind,'identity'=>$id,'disposition'=>$state,'reason'=>$reason,'manual_review_required'=>true];$this->revision++;
  $r=['schema'=>'DF_WORKFLOW_RECEIPT_1','kind'=>$kind,'scope_fingerprint'=>$q['scope_fingerprint'],'actor_id'=>$q['actor_id'],'identity'=>$id,'content_hash'=>$content,'disposition'=>$state,'journal_revision'=>$this->revision,'recovery_epoch'=>str_repeat('a',64),'checkpoint_epoch'=>str_repeat('a',64),'external_execution_authorized'=>false];$r['receipt_hash']=MutationIntent::hash($r);return $r;
 }
 public function inspectWorkflows(ShopBinding $s,int $actor,int $limit):array|WP_Error {$r=['schema'=>'DF_WORKFLOW_VIEW_1','scope_fingerprint'=>$s->fingerprint(),'actor_id'=>$actor,'items'=>array_slice(array_values(array_filter($this->history,static fn($row)=>$row['scope_fingerprint']===$s->fingerprint())),-$limit),'read_only'=>true,'external_execution_authorized'=>false];if($this->fault==='view_wrong_scope'&&$r['items']!==[])$r['items'][0]['scope_fingerprint']=str_repeat('0',64);$r['evidence_hash']=MutationIntent::hash($r);return $r;}
}
