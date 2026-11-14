<?php
declare(strict_types=1);
/** Synthetic local fault model, NOT production storage or an independent witness. */
final class SyntheticRecoveryStore {
 public string $fault='';
 public function __construct(private string $path,private string $anchor){}
 private static function hash(array $v):string{return hash('sha256',json_encode($v,JSON_THROW_ON_ERROR));}
 private function locked(callable $f):array {
  $l=fopen($this->path.'.lock','c');if(!$l||!flock($l,LOCK_EX))throw new RuntimeException('unavailable');
  try{return $f();}finally{flock($l,LOCK_UN);fclose($l);}
 }
 private function write(string $p,array $v):void {if($this->fault==='storage_failure')throw new RuntimeException('unavailable');$tmp=$p.'.tmp';$f=fopen($tmp,'wb');$b=json_encode($v,JSON_THROW_ON_ERROR);if(!$f||fwrite($f,$b)!==strlen($b)||!fflush($f)||!fsync($f))throw new RuntimeException('unavailable');fclose($f);if(!rename($tmp,$p))throw new RuntimeException('unavailable');}
 public function initialize():void {$this->locked(function(){if(is_file($this->path)||is_file($this->anchor))throw new RuntimeException('no rearm');$s=['generation'=>1,'revision'=>0,'stopped'=>false,'events'=>[],'records'=>[]];$this->write($this->anchor,['generation'=>1,'revision'=>0,'head'=>str_repeat('0',64)]);$this->write($this->path,$s);return [];});}
 private function load():array {
  $s=json_decode(file_get_contents($this->path),true,32,JSON_THROW_ON_ERROR);$a=json_decode(file_get_contents($this->anchor),true,32,JSON_THROW_ON_ERROR);$head=str_repeat('0',64);$rev=0;
  foreach($s['events']as $e){$h=$e['hash'];unset($e['hash']);if($e['previous']!==$head||$e['revision']!==++$rev||!hash_equals(self::hash($e),$h))throw new RuntimeException('chain mismatch');$head=$h;}
  if($s['revision']!==$rev||$a!==['generation'=>$s['generation'],'revision'=>$rev,'head'=>$head])throw new RuntimeException('checkpoint mismatch');
  if($s['events']===[]&&($s['records']!==[]||$s['stopped']!==false||$s['generation']!==1))throw new RuntimeException('missing initial journal');
  $last=$s['events']===[]?null:end($s['events']);if($last!==null&&!hash_equals($last['state_hash'],self::hash([$s['records'],$s['stopped'],$s['generation']])))throw new RuntimeException('state mismatch');return $s;
 }
 public function inspect():array{return $this->locked(fn()=>$this->load());}
 public function transition(string $id,string $action,int $generation,int $now):array {
  return $this->locked(function()use($id,$action,$generation,$now){$s=$this->load();if($generation!==$s['generation'])throw new RuntimeException('stale generation');$old=$s['records'][$id]??null;if($s['stopped']&&$action!=='EMERGENCY')throw new RuntimeException('no automatic rearm');
   if($action==='EMERGENCY'){$s['stopped']=true;$s['generation']++;foreach($s['records']as &$r)if($r['state']==='RESERVED')$r['state']='UNKNOWN';unset($r);}
   elseif($action==='PREPARE'){if($old!==null)throw new RuntimeException('duplicate');$s['records'][$id]=['state'=>'PREPARED','lease_until'=>0,'generation'=>$generation];}
   elseif($action==='RESERVE'){if(($old['state']??null)!=='PREPARED'||$old['generation']!==$generation)throw new RuntimeException('one shot');$s['records'][$id]=['state'=>'RESERVED','lease_until'=>$now+20,'generation'=>$generation];}
   elseif($action==='EXPIRE'){if(($old['state']??null)!=='RESERVED'||$old['lease_until']>$now)throw new RuntimeException('lease not expired');$s['records'][$id]['state']='UNKNOWN';}
   elseif($action==='CONFLICT'){if(!in_array($old['state']??null,['RESERVED','UNKNOWN'],true))throw new RuntimeException('no fence');$s['records'][$id]['state']='CONFLICTING';}
   else throw new RuntimeException('fixed action required');
   $e=['revision'=>++$s['revision'],'previous'=>$s['events']===[]?str_repeat('0',64):end($s['events'])['hash'],'generation'=>$s['generation'],'state_hash'=>self::hash([$s['records'],$s['stopped'],$s['generation']])];$e['hash']=self::hash($e);$s['events'][]=$e;
   // Witness first: interruption between stores fails closed, never silently repairs either root.
   $this->write($this->anchor,['generation'=>$s['generation'],'revision'=>$s['revision'],'head'=>$e['hash']]);if($this->fault==='journal_failure')throw new RuntimeException('interrupted');$this->write($this->path,$s);if($this->fault==='lost_ack')throw new RuntimeException('lost acknowledgement');return $s;
  });
 }
}
