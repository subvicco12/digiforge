<?php
declare(strict_types=1);
namespace DigiForge\Operations;
use DigiForge\Database\Tables;
/** Bounded audit identity projection. Context is deliberately excluded to avoid secret/sensitive leakage. */
final class AuditCorrelationReadModel {
 public function recent(string $objectType='',string $objectId='',int $limit=50, ?string &$queryState=null):array {
  global $wpdb;$limit=max(1,min(100,$limit));$where=[];$args=[];if($objectType!==''){$where[]='object_type=%s';$args[]=sanitize_key($objectType);}if($objectId!==''){$where[]='object_id=%s';$args[]=sanitize_text_field($objectId);}$sql='SELECT id,event_type,actor_id,object_type,object_id,created_at FROM '.Tables::audit_log().($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY id DESC LIMIT %d';$args[]=$limit;$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare($sql,...$args),ARRAY_A);if(!is_array($rows)||!empty($wpdb->last_error)){$queryState='UNAVAILABLE';return [];}$queryState='AVAILABLE';return array_map(static function(array $r):array{$r['read_only']=true;$r['context_exposed']=false;$r['retry_permitted']=false;$r['external_execution_authorized']=false;return $r;},$rows);
 }
}