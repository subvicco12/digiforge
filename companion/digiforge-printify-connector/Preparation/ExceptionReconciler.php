<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
/** Pure triage only. Never converts an observation into a retry, state change or approval. */
final class ExceptionReconciler {
 public static function classify(array $item,?ShipmentReadModel $shipment=null,?array $recovery=null):array {
  ReviewContract::item($item,$item['scope_fingerprint']??'', $item['authorization_revision']??0);$class='MANUAL_REVIEW';$next='INSPECT_CORE_EVIDENCE';
  if($item['operation_state']==='UNKNOWN'){$class='UNCERTAIN';$next='INSPECT_AUTHORITATIVE_RECEIPTS';}elseif($item['operation_state']==='CONFLICTING'){$class='CONFLICT';$next='COMPARE_CORE_JOURNAL_RECEIPTS';}elseif($item['operation_state']==='CANCELLED'){$class='CANCELLED_OBSERVATION';$next='VERIFY_PROVIDER_CANCELLATION';}
  if($shipment!==null){$d=$shipment->jsonSerialize();if($d['scope_fingerprint']!==$item['scope_fingerprint']||$item['kind']!=='FULFILLMENT'||$item['subject_hash']!==$d['order_identity'])throw new \InvalidArgumentException('Exact core subject lineage required.');if($d['state']==='PARTIAL_TRACKING_OBSERVED'&&!in_array($item['operation_state'],['UNKNOWN','CONFLICTING','CANCELLED'],true)){$class='PARTIAL_OBSERVATION';$next='VERIFY_REMAINING_LINE_ITEMS';}}
  if(in_array('VERSION_STALE',$item['blockers'],true)||in_array('SNAPSHOT_STALE',$item['blockers'],true)){$class='STALE';$next='OBTAIN_CURRENT_CORE_EVIDENCE';}
  if($recovery!==null){if(!NativePortSchema::valid('recovery_receipt',$recovery)||$recovery['scope_fingerprint']!==$item['scope_fingerprint'])throw new \InvalidArgumentException('Scoped core recovery evidence required.');if($recovery['journal_state']==='MISSING'){$class='MISSING_JOURNAL';$next='PRESERVE_UNCERTAINTY';}elseif($recovery['generation']!==$recovery['checkpoint_generation']||$recovery['recovery_epoch']!==$recovery['checkpoint_epoch']){$class='STALE';$next='OBTAIN_CURRENT_CORE_EVIDENCE';}}
  return ['classification'=>$class,'next_evidence'=>$next,'operation_state'=>$item['operation_state'],'subject_hash'=>$item['subject_hash'],'core_verification_required'=>true,'manual_review_required'=>true,'apply_state'=>false,'resolution_authorized'=>false,'retry_permitted'=>false,'external_execution_authorized'=>false];
 }
}
