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
}