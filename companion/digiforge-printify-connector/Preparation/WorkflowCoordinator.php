<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
use DigiForge\Connector\Printify\ShopBinding;
/** Local observation intake only. Core owns proof resolution, journal, dedupe and ordering atomically. */
final class WorkflowCoordinator {
 public const VERSION='1.0.0';
 public function __construct(private ?WorkflowCoreInterface $core=null){}
 public static function discover():self{try{$class='DigiForge\\Connector\\Printify\\MutationBridge';if(!class_exists($class)||!method_exists($class,'connect'))return new self();$port=$class::connect(VersionedMutationHost::VERSION);return new self($port instanceof WorkflowCoreInterface?$port:null);}catch(\Throwable){return new self();}}
 private function authorized(ShopBinding $s,int $actor,string $action):bool {
  try{if($actor<1||$s->expiresAt<=time()||$this->core===null||$this->core->version()!==VersionedMutationHost::VERSION||$this->core->workflowVersion()!==self::VERSION)return false;$a=$this->core->authorizeWorkflow($s,$actor,$action);if(is_wp_error($a))return false;
   $expected=['scope_fingerprint'=>$s->fingerprint(),'actor_id'=>$actor,'action'=>$action,'authorization_revision'=>$s->revision,'local_evidence_authorized'=>true,'external_writes_denied'=>true];if(array_diff(array_keys($a),array_merge(array_keys($expected),['expires_at'])))return false;foreach($expected as $k=>$v)if(($a[$k]??null)!==$v)return false;return is_int($a['expires_at']??null)&&$a['expires_at']>time();
  }catch(\Throwable){return false;}
 }
 private function record(ShopBinding $s,int $actor,string $kind,array $e):array|\WP_Error {
  $action=$kind==='ORDER_OBSERVATION'?'record_order':'receive_webhook';
  if(!$this->authorized($s,$actor,$action))return self::error();
  try{$q=['schema'=>'DF_WORKFLOW_INTAKE_1','scope_fingerprint'=>$s->fingerprint(),'actor_id'=>$actor,'kind'=>$kind,'evidence'=>$e];$r=$this->core->recordWorkflow($q);if(is_wp_error($r))return self::error();
   $keys=['schema','kind','scope_fingerprint','actor_id','identity','content_hash','disposition','journal_revision','recovery_epoch','checkpoint_epoch','external_execution_authorized','receipt_hash'];if(array_diff(array_keys($r),$keys)||array_diff($keys,array_keys($r)))return self::error();
   $identity=$kind==='ORDER_OBSERVATION'?($e['external_identity']??$e['provider_identity']):$e['dedupe_key'];$content=$kind==='WEBHOOK_EVIDENCE'?$e['payload_hash']:$e['content_hash'];
   foreach(['schema'=>'DF_WORKFLOW_RECEIPT_1','kind'=>$kind,'scope_fingerprint'=>$s->fingerprint(),'actor_id'=>$actor,'identity'=>$identity,'content_hash'=>$content,'external_execution_authorized'=>false]as $k=>$v)if($r[$k]!==$v)return self::error();
   if(!in_array($r['disposition'],['RECORDED','DUPLICATE','CONFLICT','OUT_OF_ORDER','REJECTED'],true)||!is_int($r['journal_revision'])||$r['journal_revision']<1||!is_string($r['recovery_epoch'])||!preg_match('/^[a-f0-9]{64}$/D',$r['recovery_epoch'])||$r['checkpoint_epoch']!==$r['recovery_epoch'])return self::error();$hash=$r['receipt_hash'];unset($r['receipt_hash']);if(!is_string($hash)||!hash_equals(MutationIntent::hash($r),$hash)||!$this->authorized($s,$actor,$action))return self::error();
   return ['kind'=>$kind,'identity'=>$identity,'disposition'=>$r['disposition'],'journal_revision'=>$r['journal_revision'],'receipt_hash'=>$hash,'manual_review_required'=>true,'retry_permitted'=>false,'business_transition_authorized'=>false,'external_execution_authorized'=>false];
  }catch(\Throwable){return self::error();}
 }
 public function ingestOrder(ShopBinding $s,int $actor,OrderReadModel $order):array|\WP_Error{$e=$order->jsonSerialize();if($e['scope_fingerprint']!==$s->fingerprint())return self::error();return $this->record($s,$actor,'ORDER_OBSERVATION',$e);}
 public function webhook(ShopBinding $s,int $actor,string $raw,string $signature):array|\WP_Error {
  if(strlen($raw)>1048576||!$this->authorized($s,$actor,'receive_webhook'))return self::error();
  try{$e=$this->core->withWebhookSecret($s,static fn(string $secret)=>WebhookContract::verify($s,$raw,$signature,$secret,time()));if(!is_array($e)||($e['signature_verified']??false)!==true||($e['scope_fingerprint']??null)!==$s->fingerprint())return self::error();return $this->record($s,$actor,'WEBHOOK_EVIDENCE',$e);}
  catch(\Throwable){$hash=hash('sha256',$raw);$e=['dedupe_key'=>MutationIntent::hash([$s->fingerprint(),'rejection',$hash]),'content_hash'=>$hash,'reason'=>'WEBHOOK_UNTRUSTED','external_execution_authorized'=>false];$this->record($s,$actor,'WEBHOOK_REJECTION',$e);return self::error();}
 }
 public function snapshot(ShopBinding $s,int $actor):array|\WP_Error {
  if(!$this->authorized($s,$actor,'inspect_workflows'))return self::error();
  try{$r=$this->core->inspectWorkflows($s,$actor,20);if(is_wp_error($r)||array_diff(array_keys($r),['schema','scope_fingerprint','actor_id','items','read_only','external_execution_authorized','evidence_hash'])||($r['schema']??null)!=='DF_WORKFLOW_VIEW_1'||($r['scope_fingerprint']??null)!==$s->fingerprint()||($r['actor_id']??null)!==$actor||($r['read_only']??null)!==true||($r['external_execution_authorized']??null)!==false||!is_array($r['items']??null)||!array_is_list($r['items'])||count($r['items'])>20)return self::error();$hash=$r['evidence_hash']??null;unset($r['evidence_hash']);if(!is_string($hash)||!hash_equals(MutationIntent::hash($r),$hash))return self::error();
   foreach($r['items']as $row){if(!is_array($row)||array_diff(array_keys($row),['scope_fingerprint','kind','identity','disposition','reason','manual_review_required'])||($row['scope_fingerprint']??null)!==$s->fingerprint()||!in_array($row['kind']??null,['ORDER_OBSERVATION','WEBHOOK_EVIDENCE','WEBHOOK_REJECTION','PUBLICATION'],true)||!is_string($row['identity']??null)||!preg_match('/^[a-f0-9]{64}$/D',$row['identity'])||!in_array($row['disposition']??null,['RECORDED','DUPLICATE','CONFLICT','OUT_OF_ORDER','REJECTED','PENDING','UNKNOWN'],true)||!in_array($row['reason']??null,['ORDER_REVIEW_REQUIRED','AUTHORITATIVE_READBACK_REQUIRED','EVENT_CONFLICT','EVENT_OUT_OF_ORDER','WEBHOOK_REJECTED','PUBLICATION_PENDING','UNRESOLVED_UNKNOWN'],true)||($row['manual_review_required']??null)!==true)return self::error();}
   if(!$this->authorized($s,$actor,'inspect_workflows'))return self::error();return $r+['evidence_hash'=>$hash,'retry_permitted'=>false];
  }catch(\Throwable){return self::error();}
 }
 public function export(ShopBinding $s,int $actor):string|\WP_Error{$r=$this->snapshot($s,$actor);if(is_wp_error($r))return $r;$r['export_hash']=MutationIntent::hash($r);return json_encode($r,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);}
 private static function error():\WP_Error{return new \WP_Error('printify_workflow_denied','Verified scoped core workflow evidence required.',['external_execution_authorized'=>false,'retry_permitted'=>false]);}
}
