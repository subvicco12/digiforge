<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;

/** Immutable human acknowledgement evidence. It never authorizes retry or provider execution. */
final class ReconciliationAcknowledgement
{
 public static function acknowledge(array $reconciliation,int $reviewerId,string $decision):array|WP_Error{
  $hash=strtolower((string)($reconciliation['reconciliation_hash']??''));$unknown=strtolower((string)($reconciliation['unknown_hash']??''));$auth=strtolower((string)($reconciliation['authorization_hash']??''));
  $decision=strtoupper(trim($decision));
  if($reviewerId<1)return new WP_Error('reconciliation_reviewer_required','Authenticated human reviewer required.',['status'=>403]);
  if(!preg_match('/^[a-f0-9]{64}$/',$hash)||!preg_match('/^[a-f0-9]{64}$/',$unknown)||!preg_match('/^[a-f0-9]{64}$/',$auth)||!in_array($decision,['ACKNOWLEDGE_CONFIRMED','ACKNOWLEDGE_NOT_CONFIRMED'],true))
   return new WP_Error('reconciliation_acknowledgement_invalid','Bound reconciliation evidence and explicit acknowledgement required.',['status'=>409]);
  $resolution=(string)($reconciliation['resolution_state']??'');
  if(($decision==='ACKNOWLEDGE_CONFIRMED'&&$resolution!=='PRINTIFY_RECONCILIATION_CONFIRMED')||($decision==='ACKNOWLEDGE_NOT_CONFIRMED'&&$resolution!=='PRINTIFY_RECONCILIATION_NOT_CONFIRMED'))
   return new WP_Error('reconciliation_acknowledgement_conflict','Acknowledgement must match the durable reconciliation result.',['status'=>409]);
  $payload=['authorization_hash'=>$auth,'unknown_hash'=>$unknown,'reconciliation_hash'=>$hash,'resolution_state'=>$resolution,'decision'=>$decision,'reviewed_by'=>$reviewerId,'retry_permitted'=>false,'external_execution_authorized'=>false];
  $canonical=$payload;ksort($canonical);
  return ['state'=>'RECONCILIATION_ACKNOWLEDGED','acknowledgement'=>$payload,'acknowledgement_hash'=>hash('sha256',(string)wp_json_encode($canonical))];
 }
}
