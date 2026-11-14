<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
final class ReviewContract {
 public static function item(array $i,string $scope,int $authorizationRevision):void {
  $keys=['subject_hash','payload_hash','scope_fingerprint','kind','operation_state','review_state','review_revision','product_version','order_version','authorization_revision','recovery_epoch','blockers'];if(array_diff(array_keys($i),$keys)||array_diff($keys,array_keys($i)))self::bad();foreach(['subject_hash','payload_hash','recovery_epoch']as $k)self::digest($i[$k]);
  if($i['scope_fingerprint']!==$scope||$i['authorization_revision']!==$authorizationRevision||!in_array($i['kind'],['PUBLICATION','ORDER','FULFILLMENT'],true)||!in_array($i['operation_state'],['PUBLICATION_PENDING','ORDER_REVIEW','FULFILLMENT_REVIEW','UNKNOWN','CONFLICTING','CANCELLED','FULFILLED','EXCEPTION'],true)||!in_array($i['review_state'],['PENDING','ACKNOWLEDGED','CHANGES_REQUESTED','REJECTED','REVIEWED_LOCAL'],true))self::bad();foreach(['review_revision','product_version','order_version']as $k)if(!is_int($i[$k])||$i[$k]<($k==='review_revision'?1:0))self::bad();if(!is_array($i['blockers'])||!array_is_list($i['blockers'])||count($i['blockers'])>20)self::bad();foreach($i['blockers']as $b)if(!in_array($b,['PROOF_MISSING','VERSION_STALE','APPROVAL_REVOKED','SNAPSHOT_STALE','UNRESOLVED_UNKNOWN','EVENT_CONFLICT','LINEAGE_UNVERIFIED'],true))self::bad();
 }
 public static function transition(array $i,string $decision):string {
  if(in_array($i['review_state'],['REJECTED','REVIEWED_LOCAL'],true))self::bad();
  return match($decision){'ACKNOWLEDGE'=>'ACKNOWLEDGED','REQUEST_CORRECTION'=>'CHANGES_REQUESTED','REJECT'=>in_array($i['operation_state'],['UNKNOWN','CONFLICTING'],true)?throw new \InvalidArgumentException('Uncertainty cannot be rejected away.'):'REJECTED','REVIEW_LOCAL'=>in_array($i['operation_state'],['PUBLICATION_PENDING','ORDER_REVIEW','FULFILLMENT_REVIEW'],true)&&$i['blockers']===[]?'REVIEWED_LOCAL':throw new \InvalidArgumentException('Current resolved evidence required.'),default=>throw new \InvalidArgumentException('Fixed local review decision required.')};
 }
 public static function digest(mixed $v):void{if(!is_string($v)||!preg_match('/^[a-f0-9]{64}$/D',$v))self::bad();}
 private static function bad():never{throw new \InvalidArgumentException('Exact versioned scoped local review data required.');}
}
