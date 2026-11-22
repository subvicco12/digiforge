<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
/** Pure local candidate state. No state, hash or local fence grants provider execution. */
final class NativeHeldJournalState {
 public static function prepare(array $binding,string $candidateKey,int $now,bool $scoped=false,?string $association=null,?array $correlation=null):array {
  if(!NativePortContract::valid('binding',$binding)||$binding['credential_reference']!==$binding['integration_id']||(['personalized_pod'=>2,'standard_pod'=>3][$binding['shop_key']]??null)!==$binding['shop_id']||!preg_match('/^[a-f0-9]{64}$/D',$candidateKey)||$now<1)throw new \InvalidArgumentException('Exact candidate binding required.');
  $state=['schema'=>'DF_NATIVE_HELD_JOURNAL_1','binding'=>$binding,'candidate_key'=>$candidateKey,'state'=>'HELD','revision'=>1,'fence_hash'=>null,'occurred_at'=>$now,'approval_verified'=>false,'dispatch_permitted'=>false,'independent_checkpoint_verified'=>false];
  if($association!==null&&(!$scoped||!self::hash($association)))throw new \InvalidArgumentException("Invalid association pin.");
  if($scoped)$state['scope_recheck_required']=true;
  if($association!==null)$state['association_pin']=$association;
  if($correlation!==null){
   if(!$scoped||array_keys($correlation)!==['request_id','challenge','intent_digest']
    ||!self::token($correlation['request_id'])||!self::token($correlation['challenge'])
    ||$correlation['request_id']===$correlation['challenge']||!self::hash($correlation['intent_digest'])){
    throw new \InvalidArgumentException('Exact witness correlation required.');
   }
   $state['witness_correlation']=$correlation;
  }
  return $state;
 }
 public static function valid(array $r):bool {
  try{$base=self::prepare($r['binding']??[], $r['candidate_key']??'', $r['occurred_at']??0,($r['scope_recheck_required']??null)===true,$r['association_pin']??null,$r['witness_correlation']??null);if(isset($r['witness_receipt'])){
   if(!isset($base['witness_correlation'])||!in_array($r['revision'],[2,3],true)||!NativeWitnessReceipt::valid($r['witness_receipt']))return false;
   $base['witness_receipt']=$r['witness_receipt'];
  }
  if(isset($r['witness_outcome'])){
   if(!isset($base['witness_receipt'])||$r['revision']!==3||!NativeWitnessReceipt::valid($r['witness_outcome']))return false;
   $base['witness_outcome']=$r['witness_outcome'];
  }
  if(($r['schema']??null)!==$base['schema']||array_keys($r)!==array_keys($base)||!is_int($r['revision'])||$r['revision']<1||$r['revision']>256||$r['approval_verified']!==false||$r['dispatch_permitted']!==false||$r['independent_checkpoint_verified']!==false)return false;
   if(isset($r['witness_correlation'])&&$r['state']!==(isset($r['witness_outcome'])?'UNKNOWN':'HELD'))return false;
   return match($r['state']){'HELD'=>$r['revision']===(isset($r['witness_receipt'])?2:1)&&$r['fence_hash']===null,'CANCELLED'=>$r['revision']===2&&$r['fence_hash']===null,'FENCED'=>$r['revision']===2&&self::hash($r['fence_hash']),'UNKNOWN'=>$r['revision']===3&&(isset($r['witness_outcome'])?$r['fence_hash']===null:self::hash($r['fence_hash'])),default=>false};
  }catch(\Throwable){return false;}
 }
 public static function bindWitness(array $record,array $receipt,int $now):array {
  if(!self::valid($record)||!isset($record['witness_correlation'])||$record['revision']!==1
   ||$now<$record['occurred_at']||!NativeWitnessReceipt::valid($receipt))throw new \InvalidArgumentException('Exact held correlation required.');
  $record['revision']=2;
  $record['occurred_at']=$now;
  $record['witness_receipt']=$receipt;
  return $record;
 }
 public static function bindWitnessUnknown(array $record,array $outcome,int $now):array {
  if(!self::valid($record)||$record['revision']!==2||!isset($record['witness_receipt'])
   ||$now<$record['occurred_at']||!NativeWitnessReceipt::valid($outcome))throw new \InvalidArgumentException('Exact witness reservation required.');
  $record['state']='UNKNOWN';
  $record['revision']=3;
  $record['occurred_at']=$now;
  $record['witness_outcome']=$outcome;
  return $record;
 }
 public static function advance(array $r,string $action,int $expected,int $now,?string $fence=null):array {
  if(!self::valid($r)||isset($r['witness_correlation'])||$r['revision']!==$expected||$now<$r['occurred_at'])throw new \InvalidArgumentException('Current held revision required.');
  $next=match([$r['state'],$action]){['HELD','FENCE']=>'FENCED',['HELD','CANCEL']=>'CANCELLED',['FENCED','UNKNOWN']=>'UNKNOWN',default=>throw new \InvalidArgumentException('Held transition denied; no rearm or retry.')};
  if(($action==='FENCE'&&!self::hash($fence))||($action!=='FENCE'&&$fence!==null))throw new \InvalidArgumentException('Exact local fence required.');
  $r['state']=$next;$r['revision']++;$r['occurred_at']=$now;if($action==='FENCE')$r['fence_hash']=$fence;return $r;
 }
 private static function token(mixed $value):bool {
  // Unpadded canonical base64url for exactly 32 bytes.
  return is_string($value)&&preg_match('/^[A-Za-z0-9_-]{42}[AEIMQUYcgkosw048]$/D',$value)===1;
 }
 private static function hash(mixed $v):bool{return is_string($v)&&preg_match('/^[a-f0-9]{64}$/D',$v)===1;}
}
