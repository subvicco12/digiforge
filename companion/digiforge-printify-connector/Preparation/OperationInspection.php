<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
final class OperationInspection {
 public const VERSION='1.0.0';
 public static function read(?MutationCoreInterface $core,MutationIntent $i):array|\WP_Error {
  try{
   if(!$core instanceof MutationInspectionCoreInterface||$core->version()!==VersionedMutationHost::VERSION||$core->inspectionVersion()!==self::VERSION)throw new \RuntimeException();
   $r=$core->inspect(VersionedMutationHost::binding($i));if(is_wp_error($r))throw new \RuntimeException();
   $keys=['inspection_version','binding','read_authorized','observed_at','checkpoint_epoch','journal','audit_events','evidence_hash'];
   if(array_diff(array_keys($r),$keys)||array_diff($keys,array_keys($r))||$r['inspection_version']!==self::VERSION||$r['read_authorized']!==true||!is_array($r['binding'])||MutationIntent::hash($r['binding'])!==MutationIntent::hash(VersionedMutationHost::binding($i))||!is_int($r['observed_at'])||$r['observed_at']<time()-30||$r['observed_at']>time()+30)throw new \RuntimeException();
   self::digest($r['checkpoint_epoch']);self::digest($r['evidence_hash']);$hash=$r['evidence_hash'];unset($r['evidence_hash']);if(!hash_equals($hash,MutationIntent::hash($r)))throw new \RuntimeException();
   $j=$r['journal'];$reasons=[];$state='MISSING';$revision=null;$receipt=null;
   if($j===null)$reasons[]='JOURNAL_MISSING';else{
    $jkeys=['state','revision','epoch','approval_state','receipt_reference','event_count','fence_present'];
    if(!is_array($j)||array_diff(array_keys($j),$jkeys)||array_diff($jkeys,array_keys($j)))throw new \RuntimeException();
    $states=['PREPARED'=>'PREPARED','FENCED'=>'RESERVED','UNKNOWN'=>'UNKNOWN','METADATA_RECONCILED'=>'RECONCILED','REJECTED'=>'REJECTED','CONFLICTING'=>'CONFLICTING','CANCELLED'=>'CANCELLED'];
    if(!is_string($j['state'])||!isset($states[$j['state']])||!is_int($j['revision'])||$j['revision']<1||!is_int($j['event_count'])||$j['event_count']<0||!is_bool($j['fence_present'])||!in_array($j['approval_state'],['VALID','REVOKED','EXPIRED','UNKNOWN'],true))throw new \RuntimeException();
    self::digest($j['epoch']);if($j['receipt_reference']!==null)self::digest($j['receipt_reference']);
    if($j['state']==='FENCED'&&!$j['fence_present'])throw new \RuntimeException();
    if($j['state']==='METADATA_RECONCILED'&&($j['receipt_reference']===null||$j['event_count']<1))throw new \RuntimeException();
    $state=$states[$j['state']];$revision=$j['revision'];$receipt=$j['receipt_reference'];
    if($j['epoch']!==$r['checkpoint_epoch'])$reasons[]='SNAPSHOT_STALE';
    if($j['approval_state']!=='VALID')$reasons[]='APPROVAL_'.$j['approval_state'];
    if($j['state']==='UNKNOWN')$reasons[]='UNRESOLVED_UNKNOWN';if($j['state']==='CONFLICTING')$reasons[]='RECEIPT_CONFLICT';if($j['state']==='REJECTED')$reasons[]='OPERATION_REJECTED';
   }
   $events=$r['audit_events'];if(!is_array($events)||!array_is_list($events)||count($events)>20)throw new \RuntimeException();$last=0;
   foreach($events as $event){$ekeys=['sequence','state','occurred_at','event_hash'];if(!is_array($event)||array_diff(array_keys($event),$ekeys)||array_diff($ekeys,array_keys($event))||!is_int($event['sequence'])||$event['sequence']<=$last||!in_array($event['state'],['PREPARED','FENCED','UNKNOWN','METADATA_RECONCILED','REJECTED','CONFLICTING','CANCELLED'],true)||!is_int($event['occurred_at'])||$event['occurred_at']<1||$event['occurred_at']>$r['observed_at'])throw new \RuntimeException();self::digest($event['event_hash']);$last=$event['sequence'];}
   return ['audit_events'=>$events,'operation_id'=>$i->operationId,'operation'=>$i->operation,'shop_key'=>$i->scope->shopKey,'payload_fingerprint'=>$i->payloadFingerprint,'state'=>$state,'reasons'=>$reasons,'journal_revision'=>$revision,'receipt_reference'=>$receipt,'observed_at'=>$r['observed_at'],'core_evidence_hash'=>$hash,'retry_permitted'=>false,'external_execution_authorized'=>false];
  }catch(\Throwable){return new \WP_Error('printify_inspection_denied','Current scoped core read authority and journal evidence required.',['read_only'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
 }
 private static function digest(mixed $s):void{if(!is_string($s)||!preg_match('/^[a-f0-9]{64}$/D',$s))throw new \InvalidArgumentException();}
}
