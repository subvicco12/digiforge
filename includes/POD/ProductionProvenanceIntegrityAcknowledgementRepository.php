<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use WP_Error;

/** Durable operator acknowledgement of provenance anomalies. Evidentiary only; never execution authority. */
final class ProductionProvenanceIntegrityAcknowledgementRepository{
 /** @var null|callable Test-only seam invoked immediately before insert; production leaves null. */
 private static $beforeInsert=null;
 public static function setBeforeInsertHookForTest(?callable $hook):void{self::$beforeInsert=$hook;}
 /** @return array|WP_Error */
 public static function acknowledge(string $correlationHash,string $authorizationHash,string $anomalyType,int $reviewer):array|WP_Error{
  $correlationHash=strtolower(trim($correlationHash));$authorizationHash=strtolower(trim($authorizationHash));
  if(!preg_match('/^[a-f0-9]{64}$/',$correlationHash)||!preg_match('/^[a-f0-9]{64}$/',$authorizationHash)||!in_array($anomalyType,['BINDING_PACKAGE_MISMATCH','CLOSURE_PACKAGE_MISMATCH','BINDING_CLOSURE_MISMATCH','LEGACY_UNBOUND'],true)||$reviewer<1)return new WP_Error('production_integrity_ack_invalid','Valid integrity acknowledgement binding required.',['status'=>400]);
  $evidence=(new ProductionProvenanceIntegrityReadModel())->recent(200);$match=null;foreach($evidence['items'] as $item)if(hash_equals((string)$item['correlation_hash'],$correlationHash)&&hash_equals((string)$item['authorization_hash'],$authorizationHash)&&(string)$item['type']===$anomalyType){$match=$item;break;}
  if(!is_array($match))return new WP_Error('production_integrity_ack_evidence_missing','Acknowledgement must bind a current read-only integrity anomaly.',['status'=>409]);
  $decision='ACKNOWLEDGE_INTEGRITY_ANOMALY';$hash=hash('sha256',implode('|',[$correlationHash,$authorizationHash,$anomalyType,$decision,(string)$reviewer]));
  global $wpdb;$table=Tables::pod_provenance_integrity_acknowledgements();$wpdb->last_error='';$existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE correlation_hash=%s LIMIT 1',$correlationHash),ARRAY_A);
  if(!empty($wpdb->last_error))return new WP_Error('production_integrity_ack_evidence_unavailable','Integrity acknowledgement evidence could not be read.',['status'=>503,'retry_permitted'=>false,'external_execution_authorized'=>false]);if(is_array($existing))return hash_equals((string)$existing['acknowledgement_hash'],$hash)?self::safe($existing):new WP_Error('production_integrity_ack_conflict','Integrity anomaly already has different acknowledgement evidence.',['status'=>409]);
  $row=['correlation_hash'=>$correlationHash,'authorization_hash'=>$authorizationHash,'anomaly_type'=>$anomalyType,'decision'=>$decision,'reviewed_by'=>$reviewer,'acknowledgement_hash'=>$hash,'created_at'=>current_time('mysql',true)];
  if(is_callable(self::$beforeInsert)){(self::$beforeInsert)($row);self::$beforeInsert=null;}
  if($wpdb->insert($table,$row)===false){$wpdb->last_error='';$winner=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE correlation_hash=%s LIMIT 1',$correlationHash),ARRAY_A);if(!empty($wpdb->last_error))return new WP_Error('production_integrity_ack_confirmation_unavailable','Integrity acknowledgement outcome is uncertain; do not retry.',['status'=>503,'retry_permitted'=>false,'external_execution_authorized'=>false]);if(is_array($winner))return hash_equals((string)$winner['acknowledgement_hash'],$hash)?self::safe($winner):new WP_Error('production_integrity_ack_conflict','Integrity anomaly already has different acknowledgement evidence.',['status'=>409]);return new WP_Error('production_integrity_ack_store','Integrity acknowledgement could not be persisted.',['status'=>409]);}$row['id']=(int)$wpdb->insert_id;return self::safe($row);
 }
 /** @param array<string,mixed> $row @return array<string,mixed> */
 private static function safe(array $row):array{$row['retry_permitted']=false;$row['external_execution_authorized']=false;return $row;}
}
