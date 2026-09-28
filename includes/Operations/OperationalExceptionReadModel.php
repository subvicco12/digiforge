<?php
declare(strict_types=1);
namespace DigiForge\Operations;
use DigiForge\Database\Tables;
/** Bounded, read-only operator evidence for fulfillment and finance exceptions. */
final class OperationalExceptionReadModel {
 /** @return array<string,mixed> */
 public function snapshot(int $limit=50):array {
  global $wpdb;$limit=max(1,min(100,$limit));
  $fulfillment=$wpdb->get_results($wpdb->prepare("SELECT id,order_id,fulfillment_plan_id,intent_type,state,updated_at FROM ".Tables::fulfillment_intents()." WHERE state IN ('BLOCKED','FAILED','HUMAN_REVIEW','UNKNOWN') ORDER BY id DESC LIMIT %d",$limit),ARRAY_A)?:[];
  $fulfillmentTotal=(int)$wpdb->get_var("SELECT COUNT(*) FROM ".Tables::fulfillment_intents()." WHERE state IN ('BLOCKED','FAILED','HUMAN_REVIEW','UNKNOWN')");
  $ledger=$wpdb->get_results($wpdb->prepare("SELECT id,source_type,source_id,entry_type,currency,amount,reconciliation_state,updated_at FROM ".Tables::finance_ledger()." WHERE reconciliation_state<>'RECONCILED' ORDER BY id DESC LIMIT %d",$limit),ARRAY_A)?:[];
  $tax=$wpdb->get_results($wpdb->prepare("SELECT id,source_type,source_id,jurisdiction,tax_category,classification,review_status,updated_at FROM ".Tables::tax_classifications()." WHERE review_status NOT IN ('APPROVED','REVIEWED','REJECTED','SUPERSEDED') OR (review_status='APPROVED' AND classification='REVIEW_REQUIRED') ORDER BY id DESC LIMIT %d",$limit),ARRAY_A)?:[];
  $deny=static function(array $r):array{$r['read_only']=true;$r['retry_permitted']=false;$r['external_execution_authorized']=false;$r['money_movement_authorized']=false;$r['tax_filing_authorized']=false;return $r;};
  $fulfillment=array_map($deny,$fulfillment);$ledger=array_map($deny,$ledger);$tax=array_map($deny,$tax);
  foreach($fulfillment as &$r){$r['audit_correlation']=['object_type'=>'fulfillment_intent','object_id'=>(string)$r['id']];}unset($r);
  foreach($ledger as &$r){$r['audit_correlation']=['object_type'=>'finance_ledger','object_id'=>(string)$r['id']];}unset($r);
  foreach($tax as &$r){$r['audit_correlation']=['object_type'=>'tax_classification','object_id'=>(string)$r['id']];}unset($r);
  return ['fulfillment'=>$fulfillment,'fulfillment_total'=>$fulfillmentTotal,'finance_ledger'=>$ledger,'tax'=>$tax,'read_only'=>true,'external_execution_authorized'=>false];
 }
}
