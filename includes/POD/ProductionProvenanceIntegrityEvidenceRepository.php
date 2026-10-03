<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;

/** Append-on-first-observation integrity evidence. Never grants execution authority or retry. */
final class ProductionProvenanceIntegrityEvidenceRepository{
 /** @param array<string,mixed> $item */
 public static function observe(array $item):void{
  $correlation=(string)($item['correlation_hash']??'');$authorization=(string)($item['authorization_hash']??'');$type=(string)($item['type']??'');
  if(!preg_match('/^[a-f0-9]{64}$/',$correlation)||!preg_match('/^[a-f0-9]{64}$/',$authorization)||$type==='')return;
  global $wpdb;$table=Tables::pod_provenance_integrity_evidence();$now=current_time('mysql',true);
  $payload=$item;unset($payload['acknowledgement'],$payload['operator_state'],$payload['retry_permitted'],$payload['external_execution_authorized']);
  $wpdb->last_error='';$existing=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.$table.' WHERE correlation_hash=%s LIMIT 1',$correlation));
  if(!empty($wpdb->last_error))return;
  if($existing){$wpdb->last_error='';$updated=$wpdb->update($table,['last_observed_at'=>$now],['id'=>(int)$existing],['%s'],['%d']);if($updated===false||!empty($wpdb->last_error))self::recordPersistenceFailure('UPDATE',$correlation);return;}
  $wpdb->last_error='';$inserted=$wpdb->insert($table,['correlation_hash'=>$correlation,'authorization_hash'=>$authorization,'anomaly_type'=>$type,'package_id'=>(int)($item['package_id']??0),'package_hash'=>(string)($item['package_hash']??''),'actual_package_hash'=>(string)($item['actual_package_hash']??''),'evidence_payload'=>wp_json_encode($payload),'first_observed_at'=>$now,'last_observed_at'=>$now],['%s','%s','%s','%d','%s','%s','%s','%s','%s']);if($inserted!==1||!empty($wpdb->last_error))self::recordPersistenceFailure('INSERT',$correlation);
 }
 private static function recordPersistenceFailure(string $operation,string $correlation):void{error_log('DigiForge provenance integrity evidence persistence failed: '.sanitize_key($operation).' correlation='.substr($correlation,0,12));}
 /** @return list<array<string,mixed>> */
 public static function recent(int $limit):array{
  global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Tables::pod_provenance_integrity_evidence().' ORDER BY id DESC LIMIT %d',max(1,min(200,$limit))),ARRAY_A);$out=[];
  if(!is_array($rows)||!empty($wpdb->last_error))return [['query_state'=>'UNAVAILABLE','historical_evidence'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false]];
  foreach($rows as $row){$payload=json_decode((string)$row['evidence_payload'],true);if(!is_array($payload))$payload=[];$payload['correlation_hash']=(string)$row['correlation_hash'];$payload['authorization_hash']=(string)$row['authorization_hash'];$payload['type']=(string)$row['anomaly_type'];$payload['historical_evidence']=true;$payload['first_observed_at']=(string)$row['first_observed_at'];$payload['last_observed_at']=(string)$row['last_observed_at'];$out[]=$payload;}return $out;
 }
}
