<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
/** Pure local candidate state. No state, hash or local fence grants provider execution. */
final class NativeHeldJournalState {
 public static function prepare(array $binding,string $candidateKey,int $now):array {
  if(!NativePortContract::valid('binding',$binding)||$binding['credential_reference']!==$binding['integration_id']||(['personalized_pod'=>2,'standard_pod'=>3][$binding['shop_key']]??null)!==$binding['shop_id']||!preg_match('/^[a-f0-9]{64}$/D',$candidateKey)||$now<1)throw new \InvalidArgumentException('Exact candidate binding required.');
  return ['schema'=>'DF_NATIVE_HELD_JOURNAL_1','binding'=>$binding,'candidate_key'=>$candidateKey,'state'=>'HELD','revision'=>1,'fence_hash'=>null,'occurred_at'=>$now,'approval_verified'=>false,'dispatch_permitted'=>false,'independent_checkpoint_verified'=>false];
 }
 public static function valid(array $r):bool {
  try{$base=self::prepare($r['binding']??[], $r['candidate_key']??'', $r['occurred_at']??0);if(($r['schema']??null)!==$base['schema']||array_keys($r)!==array_keys($base)||!is_int($r['revision'])||$r['revision']<1||$r['revision']>256||$r['approval_verified']!==false||$r['dispatch_permitted']!==false||$r['independent_checkpoint_verified']!==false)return false;
   return match($r['state']){'HELD'=>$r['revision']===1&&$r['fence_hash']===null,'CANCELLED'=>$r['revision']===2&&$r['fence_hash']===null,'FENCED'=>$r['revision']===2&&self::hash($r['fence_hash']),'UNKNOWN'=>$r['revision']===3&&self::hash($r['fence_hash']),default=>false};
  }catch(\Throwable){return false;}
 }
 public static function advance(array $r,string $action,int $expected,int $now,?string $fence=null):array {
  if(!self::valid($r)||$r['revision']!==$expected||$now<$r['occurred_at'])throw new \InvalidArgumentException('Current held revision required.');
  $next=match([$r['state'],$action]){['HELD','FENCE']=>'FENCED',['HELD','CANCEL']=>'CANCELLED',['FENCED','UNKNOWN']=>'UNKNOWN',default=>throw new \InvalidArgumentException('Held transition denied; no rearm or retry.')};
  if(($action==='FENCE'&&!self::hash($fence))||($action!=='FENCE'&&$fence!==null))throw new \InvalidArgumentException('Exact local fence required.');
  $r['state']=$next;$r['revision']++;$r['occurred_at']=$now;if($action==='FENCE')$r['fence_hash']=$fence;return $r;
 }
 private static function hash(mixed $v):bool{return is_string($v)&&preg_match('/^[a-f0-9]{64}$/D',$v)===1;}
}
