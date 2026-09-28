<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;use WP_Error;
/** Unified read-only production lifecycle/history projection. Never authorizes retry or provider mutation. */
final class ProductionLifecycleHistoryReadModel{
 public function project(string $authorizationHash):array|WP_Error{
  $authorizationHash=strtolower(trim($authorizationHash));if(!preg_match('/^[a-f0-9]{64}$/',$authorizationHash))return new WP_Error('production_history_authorization_invalid','Valid authorization hash required.',['status'=>400]);
  global $wpdb;$package=null;$permit=(new ConsumedPermitOutcomeReadModel())->project($authorizationHash);if(is_wp_error($permit))return $permit;$state=(string)$permit['state'];$action='NONE';$reconciliation=null;$ack=null;
  if($state==='CONSUMED_AWAITING_OUTCOME')$action='AWAIT_TERMINAL_OUTCOME';
  elseif($state==='EXECUTION_UNKNOWN'){$unknown=(new PrintifyUnknownReconciliationReadModel())->project($authorizationHash);if(is_wp_error($unknown))return $unknown;$reconciliation=$unknown;$resolution=(string)($unknown['resolution_state']??'PRINTIFY_RECONCILIATION_UNRESOLVED');if(in_array($resolution,['PRINTIFY_RECONCILIATION_UNRESOLVED','PRINTIFY_RECONCILIATION_UNKNOWN'],true)){$state='EXECUTION_UNKNOWN_RECONCILIATION_REQUIRED';$action='RECONCILE_BEFORE_ANY_RETRY';}else{$hash=(string)($unknown['reconciliation']['reconciliation_hash']??'');$ack=$hash===''?null:$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_reconciliation_acknowledgements().' WHERE reconciliation_hash=%s LIMIT 1',$hash),ARRAY_A);$state=is_array($ack)?'EXECUTION_UNKNOWN_ACKNOWLEDGED':'EXECUTION_UNKNOWN_REVIEW_REQUIRED';$action=is_array($ack)?'NONE':'REVIEW_RECONCILIATION_RESULT';}}
  $closure=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_lifecycle_closures().' WHERE authorization_hash=%s LIMIT 1',$authorizationHash),ARRAY_A);if(is_array($closure)){$state='LIFECYCLE_CLOSED';$action='NONE';$package=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_authorization_packages().' WHERE id=%d LIMIT 1',(int)$closure['package_id']),ARRAY_A);}
  return ['authorization_hash'=>$authorizationHash,'lifecycle_state'=>$state,'operator_action'=>$action,'retry_permitted'=>false,'external_execution_authorized'=>false,'authorization_package'=>$package,'permit'=>$permit,'reconciliation'=>$reconciliation,'acknowledgement'=>$ack,'closure'=>$closure,'read_only'=>true];
 }
 public function recent(int $limit=50):array{$limit=max(1,min(200,$limit));global $wpdb;$rows=$wpdb->get_results($wpdb->prepare('SELECT authorization_hash FROM '.Tables::pod_execution_nonces().' ORDER BY id DESC LIMIT %d',$limit),ARRAY_A);$items=[];foreach((array)$rows as $row){$item=$this->project((string)$row['authorization_hash']);if(!is_wp_error($item))$items[]=$item;}return ['items'=>$items,'count'=>count($items),'read_only'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false];}
}
