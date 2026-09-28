<?php
declare(strict_types=1);
namespace DigiForge\Queue;
use DigiForge\Database\Tables;
/** Bounded, read-only queue evidence for live-site operator drill-down. */
final class OperatorQueueReadModel {
 /** @return list<array<string,mixed>> */
 public function recentAttention(int $limit=50):array {
  global $wpdb;$limit=max(1,min(100,$limit));
  $sql="SELECT id,job_type,state,attempts,max_attempts,last_error,next_attempt_at,lease_expires_at,created_at,updated_at FROM ".Tables::jobs()." WHERE state IN ('FAILED','BLOCKED','HUMAN_REVIEW','DEAD_LETTER') ORDER BY id DESC LIMIT %d";
  $rows=$wpdb->get_results($wpdb->prepare($sql,$limit),ARRAY_A);if(!is_array($rows))return [];
  return array_map(static function(array $r):array{$r['read_only']=true;$r['retry_permitted']=false;$r['external_execution_authorized']=false;return $r;},$rows);
 }
 /** Read-only reliability diagnostics. Detection never authorizes replay/retry. */
 public function diagnostics():array {
  global $wpdb;$now=current_time('mysql',true);
  $orphans=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".Tables::jobs()." WHERE state='RUNNING' AND lease_expires_at IS NOT NULL AND lease_expires_at<%s",$now));
  $dead=(int)$wpdb->get_var("SELECT COUNT(*) FROM ".Tables::jobs()." WHERE state='DEAD_LETTER'");
  $retry=(int)$wpdb->get_var("SELECT COUNT(*) FROM ".Tables::jobs()." WHERE state='FAILED' AND attempts<max_attempts");
  $rate=(int)$wpdb->get_var("SELECT COUNT(*) FROM ".Tables::jobs()." WHERE state IN ('FAILED','BLOCKED','HUMAN_REVIEW') AND (LOWER(last_error) LIKE '%rate limit%' OR LOWER(last_error) LIKE '%429%')");
  $dedup=(int)$wpdb->get_var("SELECT COUNT(*) FROM ".Tables::idempotency()." WHERE status IN ('PENDING','UNKNOWN')");
  return ['orphaned_expired_leases'=>$orphans,'dead_letter_jobs'=>$dead,'retry_eligible_by_attempt_count'=>$retry,'rate_limit_evidence'=>$rate,'unresolved_idempotency_evidence'=>$dedup,'replay_permitted'=>false,'retry_permitted'=>false,'external_execution_authorized'=>false,'read_only'=>true];
 }
}