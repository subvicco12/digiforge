<?php
declare(strict_types=1);
namespace DigiForge\POD;
/** Independently provisioned local append-only emergency journal. Never a permit or default. */
final class PrintifyAcceptanceEmergencyFence {
 private const MAX_BYTES=33286;
 public function __construct(private string $directory) {}
 private static function deny():\RuntimeException {return new \RuntimeException('ACCEPTANCE_FENCE_UNAVAILABLE');}
 /** Existing private files only. No mkdir, initial epoch, truncation, rename or automatic reset. */
 private function open(bool $append=false,string $name='events'):mixed {
  clearstatcache();$d=@lstat($this->directory);$canonical=@realpath($this->directory);
  if(!function_exists('posix_geteuid')||!is_array($d)||$canonical!==$this->directory||($d['mode']&0170000)!==0040000||($d['mode']&0777)!==0700||$d['uid']!==posix_geteuid())throw self::deny();
  foreach([defined('ABSPATH')?(string)ABSPATH:'',(string)($_SERVER['DOCUMENT_ROOT']??'')] as $public){$root=$public!==''?realpath($public):false;if(is_string($root)&&($canonical===$root||str_starts_with($canonical,rtrim($root,'/').'/')))throw self::deny();}
  $path=$canonical.'/'.$name;$before=@lstat($path);
  if(!self::validStat($before))throw self::deny();
  // One bounded append; readers never hold a lock that can delay emergency invalidation.
  $h=@fopen($path,$append?'ab':'rb');if(!is_resource($h))throw self::deny();
  if(!self::same($before,fstat($h))||!self::same(fstat($h),@lstat($path))){fclose($h);throw self::deny();}return $h;
 }
 private static function validStat(mixed $s):bool {return is_array($s)&&($s['mode']&0170000)===0100000&&($s['mode']&0777)===0600&&$s['nlink']===1&&$s['uid']===posix_geteuid()&&$s['size']<=self::MAX_BYTES;}
 private static function same(mixed $a,mixed $b):bool {return is_array($a)&&is_array($b)&&$a['dev']===$b['dev']&&$a['ino']===$b['ino']&&self::validStat($b);}
 private function read(mixed $h):string {
  $before=fstat($h);if(!rewind($h))throw self::deny();$raw=stream_get_contents($h,self::MAX_BYTES+1);$after=fstat($h);
  if(!is_array($before)||!is_array($after)||$before['size']!==$after['size']||!is_string($raw)||strlen($raw)!==$after['size'])throw self::deny();
  if(!is_string($raw)||strlen($raw)>self::MAX_BYTES||preg_match('/\ADFPF1\n(?:[a-f0-9]{64}\n){1,512}\z/D',$raw)!==1)throw self::deny();
  clearstatcache(true,$this->directory.'/events');if(!self::same(fstat($h),@lstat($this->directory.'/events')))throw self::deny();return $raw;
 }
 public function snapshot():string {
  $h=$this->open();try{$raw=$this->read($h);$gate=$this->gate();fclose($gate);return hash('sha256',$raw);}finally{fclose($h);}
 }
 /** Fsync the independent event BEFORE database work; a caller SQL rollback cannot erase it. */
 public function invalidate():string {
  $check=$this->open();try{$raw=$this->read($check);}finally{fclose($check);}
  $h=$this->open(true);try{$line=bin2hex(random_bytes(32))."\n";
   if(fwrite($h,$line)!==strlen($line)||!fflush($h)||!function_exists('fsync')||!fsync($h))throw self::deny();
   clearstatcache(true,$this->directory.'/events');if(!self::same(fstat($h),@lstat($this->directory.'/events')))throw self::deny();
  }finally{fclose($h);}
  // Concurrent complete appends are valid; a partial append poisons the journal and denies.
  return $this->snapshot();
 }
 private function gate():mixed {
  $h=$this->open(false,'dispatch.lock');try{if(stream_get_contents($h,64)!=="DF_DISPATCH_GATE_V1\n")throw self::deny();$id=$this->open(false,'dispatch.identity');try{$raw=stream_get_contents($id,72);clearstatcache(true,$this->directory.'/dispatch.identity');if(!self::same(fstat($id),@lstat($this->directory.'/dispatch.identity')))throw self::deny();}finally{fclose($id);}
   $stat=fstat($h);if($raw!=="DFG1:".hash('sha256',$stat['dev'].':'.$stat['ino'])."\n")throw self::deny();return $h;
  }catch(\Throwable $e){fclose($h);throw $e;}
 }
 /** Local linearization resource only; caller retains every provider authorization check. */
 private function leased(int $mode,callable $work):mixed {
  $h=$this->gate();try{
   if(!flock($h,$mode|LOCK_NB))throw new \RuntimeException($mode===LOCK_EX?'ACCEPTANCE_FENCE_DRAIN_BUSY':'ACCEPTANCE_FENCE_DISPATCH_BUSY');
   clearstatcache(true,$this->directory.'/dispatch.lock');if(!self::same(fstat($h),@lstat($this->directory.'/dispatch.lock')))throw self::deny();return $work();
  }finally{flock($h,LOCK_UN);fclose($h);}
 }
 public function duringDispatch(callable $work):mixed {return $this->leased(LOCK_SH,$work);}
 public function afterDraining(callable $work):mixed {return $this->leased(LOCK_EX,$work);}
}
