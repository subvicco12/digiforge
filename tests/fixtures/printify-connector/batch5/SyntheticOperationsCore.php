<?php
declare(strict_types=1);
require_once __DIR__.'/../batch4/SyntheticWorkflowCore.php';
use DigiForgePrintifyConnector\Preparation\{OperationsCoreInterface,MutationIntent};
use DigiForge\Connector\Printify\ShopBinding;
/** Synthetic single-core authority fixture. Not a production store or durability certificate. */
final class SyntheticOperationsCore implements OperationsCoreInterface {
 public ?DigiForgePrintifyConnector\Preparation\ShipmentReadModel $inspectionModel=null;public string $fault='';public string $kind='PUBLICATION';public string $operationState='PUBLICATION_PENDING';public int $epoch=1;private SyntheticWorkflowCore $base;private ?array $review=null;private array $fulfillments=[];
 public function __construct(){$this->base=new SyntheticWorkflowCore();}
 public function version():string{return '1.0.0';}public function workflowVersion():string{return '1.0.0';}public function operationsVersion():string{return $this->fault==='incompatible'?'9.0.0':'1.0.0';}
 public function exchange(array $q):array|WP_Error{return new WP_Error('no_dispatch');}
 public function authorizeWorkflow(ShopBinding $s,int $actor,string $action):array|WP_Error{$this->base->fault=$this->fault;$a=$this->base->authorizeWorkflow($s,$actor,$action);if(is_array($a)){if($this->fault==='expired')$a['expires_at']=1;if($this->fault==='stale_revision')$a['authorization_revision']++;}return $a;}
 public function withWebhookSecret(ShopBinding $s,callable $v):mixed{return $this->base->withWebhookSecret($s,$v);}
 public function recordWorkflow(array $q):array|WP_Error{return $this->base->recordWorkflow($q);}public function inspectWorkflows(ShopBinding $s,int $a,int $l):array|WP_Error{return $this->base->inspectWorkflows($s,$a,$l);}
 private function epochHash():string{return hash('sha256','synthetic-review-epoch-'.$this->epoch);}
 public function reviewQueue(ShopBinding $s,int $actor,int $limit):array|WP_Error {
  if($this->review===null)$this->review=['subject_hash'=>str_repeat('c',64),'payload_hash'=>str_repeat('d',64),'scope_fingerprint'=>$s->fingerprint(),'kind'=>$this->kind,'operation_state'=>$this->operationState,'review_state'=>'PENDING','review_revision'=>1,'product_version'=>1,'order_version'=>1,'authorization_revision'=>$s->revision,'recovery_epoch'=>$this->epochHash(),'blockers'=>[]];
  $items=[$this->review];if($this->fault==='wrong_row_shop')$items[0]['scope_fingerprint']=str_repeat('0',64);if($this->fault==='stale_product')$items[0]['product_version']=2;if($this->fault==='stale_order')$items[0]['order_version']=2;if($this->fault==='private_export')$items[0]['private']='private-marker';
  $q=['schema'=>'DF_REVIEW_QUEUE_1','scope_fingerprint'=>$s->fingerprint(),'actor_id'=>$actor,'checkpoint_epoch'=>$this->epochHash(),'items'=>$items,'read_only'=>true,'external_execution_authorized'=>false];$q['evidence_hash']=MutationIntent::hash($q);return $q;
 }
 public function decideReview(array $q):array|WP_Error {
  if($this->fault==='storage_failure'||$this->review===null||$q['item']!==$this->review||$q['item']['recovery_epoch']!==$this->epochHash())return new WP_Error('synthetic_cas_denied');$this->review['review_state']=$q['next_review_state'];$this->review['review_revision']++;
  $r=['schema'=>'DF_LOCAL_REVIEW_RECEIPT_1','request_hash'=>$q['request_hash'],'scope_fingerprint'=>$q['scope_fingerprint'],'actor_id'=>$q['actor_id'],'subject_hash'=>$this->review['subject_hash'],'operation_state'=>$this->review['operation_state'],'review_state'=>$this->review['review_state'],'review_revision'=>$this->review['review_revision'],'recovery_epoch'=>$this->epochHash(),'external_execution_authorized'=>false];$r['receipt_hash']=MutationIntent::hash($r);if($this->fault==='lost_ack')return new WP_Error('synthetic_lost_ack');return $r;
 }
 public function inspectFulfillment(ShopBinding $s,int $actor,string $identity):DigiForgePrintifyConnector\Preparation\ShipmentReadModel|WP_Error{return $this->inspectionModel??new WP_Error('synthetic_missing_fulfillment');}
 public function recordFulfillment(array $q):array|WP_Error {
  if($this->fault==='storage_failure')return new WP_Error('synthetic_storage_denied');$e=$q['evidence'];$id=$e['order_identity'];$old=$this->fulfillments[$id]??null;$d='RECORDED';if($old!==null){if($e['observed_at']<$old['observed_at'])$d='OUT_OF_ORDER';elseif($e['content_hash']===$old['content_hash'])$d='DUPLICATE';elseif($e['observed_at']===$old['observed_at'])$d='CONFLICT';}if($d==='RECORDED')$this->fulfillments[$id]=$e;
  $r=['schema'=>'DF_FULFILLMENT_RECEIPT_1','scope_fingerprint'=>$q['scope_fingerprint'],'actor_id'=>$q['actor_id'],'order_identity'=>$id,'content_hash'=>$e['content_hash'],'order_version'=>$e['order_version'],'disposition'=>$d,'recovery_epoch'=>$this->epochHash(),'checkpoint_epoch'=>$this->epochHash(),'external_execution_authorized'=>false];$r['receipt_hash']=MutationIntent::hash($r);return $r;
 }
}
