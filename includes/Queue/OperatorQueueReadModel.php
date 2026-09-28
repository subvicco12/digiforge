<?php
declare(strict_types=1);
namespace DigiForge\Queue;
use DigiForge\Database\Tables;
/** Bounded, read-only queue evidence for live-site operator drill-down. */
final class OperatorQueueReadModel {
 /** Exact attention record for navigation; a changed state fails closed. */
 public function attentionById(int $id):?array {
  if($id<1)return null;
  global $wpdb;
  $row=$wpdb->get_row($wpdb->prepare("SELECT id,job_type,state,attempts,max_attempts,last_error,next_attempt_at,lease_expires_at,created_at,updated_at FROM ".Tables::jobs()." WHERE id=%d AND state IN ('FAILED','BLOCKED','HUMAN_REVIEW','DEAD_LETTER')",$id),ARRAY_A);
  if(!is_array($row))return null;
  return $row+['read_only'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false];
 }
 /** @return list<array<string,mixed>> */
 public function recentAttention(int $limit=50, ?string &$queryState=null):array {
  global $wpdb;$limit=max(1,min(100,$limit));
  $sql="SELECT id,job_type,state,attempts,max_attempts,last_error,next_attempt_at,lease_expires_at,created_at,updated_at FROM ".Tables::jobs()." WHERE state IN ('FAILED','BLOCKED','HUMAN_REVIEW','DEAD_LETTER') ORDER BY id DESC LIMIT %d";
  $rows=$wpdb->get_results($wpdb->prepare($sql,$limit),ARRAY_A);if(!is_array($rows)||!empty($wpdb->last_error)){$queryState='UNAVAILABLE';return [];}$queryState='AVAILABLE';
  return array_map(static function(array $r):array{$r['read_only']=true;$r['retry_permitted']=false;$r['external_execution_authorized']=false;return $r;},$rows);
 }
 /** Read-only reliability diagnostics. Detection never authorizes replay/retry. */
 public function diagnostics():array {
  global $wpdb;$now=current_time('mysql',true);
  $count=static function(string $sql)use($wpdb):?int{$value=$wpdb->get_var($sql);return is_numeric($value)&&empty($wpdb->last_error)?(int)$value:null;};
  $orphans=$count($wpdb->prepare("SELECT COUNT(*) FROM ".Tables::jobs()." WHERE state='RUNNING' AND lease_expires_at IS NOT NULL AND lease_expires_at<%s",$now));
  $dead=$count("SELECT COUNT(*) FROM ".Tables::jobs()." WHERE state='DEAD_LETTER'");
  $retry=$count("SELECT COUNT(*) FROM ".Tables::jobs()." WHERE state='FAILED' AND attempts<max_attempts");
  $rate=$count("SELECT COUNT(*) FROM ".Tables::jobs()." WHERE state IN ('FAILED','BLOCKED','HUMAN_REVIEW') AND (LOWER(last_error) LIKE '%rate limit%' OR LOWER(last_error) LIKE '%429%')");
  $dedup=$count("SELECT COUNT(*) FROM ".Tables::idempotency()." WHERE status IN ('PENDING','UNKNOWN')");
  $available=!in_array(null,[$orphans,$dead,$retry,$rate,$dedup],true);
  return ['query_state'=>$available?'AVAILABLE':'UNAVAILABLE','orphaned_expired_leases'=>$orphans,'dead_letter_jobs'=>$dead,'retry_eligible_by_attempt_count'=>$retry,'rate_limit_evidence'=>$rate,'unresolved_idempotency_evidence'=>$dedup,'replay_permitted'=>false,'retry_permitted'=>false,'external_execution_authorized'=>false,'read_only'=>true];
 }
 public function unresolvedIdempotency(int $limit=25, ?string &$queryState=null):array {
  global $wpdb;$limit=max(1,min(100,$limit));$rows=$wpdb->get_results($wpdb->prepare("SELECT id,operation_key,operation_type,status,response_hash,created_at,updated_at FROM ".Tables::idempotency()." WHERE status IN ('PENDING','UNKNOWN') ORDER BY id DESC LIMIT %d",$limit),ARRAY_A);if(!is_array($rows)||!empty($wpdb->last_error)){$queryState='UNAVAILABLE';return [];}$queryState='AVAILABLE';
  return array_map(static function(array $r):array{$r['operation_key_hash']=hash('sha256',(string)$r['operation_key']);unset($r['operation_key']);$r['read_only']=true;$r['replay_permitted']=false;$r['retry_permitted']=false;$r['external_execution_authorized']=false;return $r;},$rows);
 }
 public function recoveryClassification():array {
  $d=$this->diagnostics();$classification=[];
  if(($d['query_state']??'UNAVAILABLE')!=='AVAILABLE')$classification[]='QUEUE_EVIDENCE_UNAVAILABLE_REQUIRES_REVIEW';
  if(($d['orphaned_expired_leases']??0)>0)$classification[]='ORPHAN_EVIDENCE_REQUIRES_OPERATOR_REVIEW';
  if(($d['rate_limit_evidence']??0)>0)$classification[]='RATE_LIMIT_EVIDENCE_REQUIRES_BACKOFF_REVIEW';
  if(($d['unresolved_idempotency_evidence']??0)>0)$classification[]='IDEMPOTENCY_EVIDENCE_REQUIRES_RECONCILIATION';
  if(($d['dead_letter_jobs']??0)>0)$classification[]='DEAD_LETTER_REQUIRES_OPERATOR_REVIEW';
  return ['classifications'=>$classification,'automatic_recovery_permitted'=>false,'replay_permitted'=>false,'retry_permitted'=>false,'external_execution_authorized'=>false,'read_only'=>true];
 }
 public function recoveryEvidence(int $limit=25):array {
  $attentionState=null;$idemState=null;$attention=$this->recentAttention($limit,$attentionState);$idem=$this->unresolvedIdempotency($limit,$idemState);$class=$this->recoveryClassification();
  $available=$attentionState==='AVAILABLE'&&$idemState==='AVAILABLE';
  return ['query_state'=>$available?'AVAILABLE':'UNAVAILABLE','attention'=>$attention,'unresolved_idempotency'=>$idem,'classification'=>$class,'correlation'=>['attention_count'=>$attentionState==='AVAILABLE'?count($attention):null,'idempotency_count'=>$idemState==='AVAILABLE'?count($idem):null,'requires_operator_review'=>!$available||$attention!==[]||$idem!==[]],'read_only'=>true,'automatic_recovery_permitted'=>false,'replay_permitted'=>false,'retry_permitted'=>false,'external_execution_authorized'=>false];
 }
}
