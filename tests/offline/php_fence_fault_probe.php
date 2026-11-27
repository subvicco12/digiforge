<?php
declare(strict_types=1);
// Isolated process only: namespace interception never loaded by plugin/runtime.
namespace DigiForge\POD;
function fsync($stream):bool {
 $mode=getenv('DF_FAULT_CASE');
 if($mode==='failed_fsync')return false;
 $ok=\fsync($stream);
 if($mode==='kill_after_fsync'&&$ok){echo "SYNCED\n";\fflush(STDOUT);\posix_kill(\getmypid(),9);}
 return $ok;
}
function fwrite($stream,string $data):int|false {
 if(getenv('DF_FAULT_CASE')==='kill_partial'){
  \fwrite($stream,substr($data,0,11));\fflush($stream);\posix_kill(\getmypid(),9);
 }
 return \fwrite($stream,$data);
}
require __DIR__.'/../../includes/POD/PrintifyAcceptanceEmergencyFence.php';
$f=new PrintifyAcceptanceEmergencyFence($argv[1]);
try{
 if($argv[2]==='replace') {
  $mode=getenv('DF_FAULT_CASE');$path=$argv[1].'/events.pending';
  $bytes=$mode==='replace_partial'?"DFPF1\npartial":file_get_contents($argv[1].'/events').str_repeat('b',64)."\n";
  $h=\fopen($path,'xb');\chmod($path,0600);\fwrite($h,$bytes);\fflush($h);\fsync($h);\fclose($h);
  if($mode!=='replace_before')\rename($path,$argv[1].'/events');
  \posix_kill(\getmypid(),9);
 }
 $result=match($argv[2]){
  'snapshot'=>$f->snapshot(),
  'invalidate'=>$f->invalidate(),
  'drain'=>$f->afterDraining(static fn()=>true),
  'hold'=>$f->duringDispatch(static function(){echo "LEASED\n";\fflush(STDOUT);fgets(STDIN);return true;}),
  default=>throw new \RuntimeException('UNKNOWN_PROBE'),
 };
 echo json_encode(['status'=>'OK','result'=>$result],JSON_THROW_ON_ERROR)."\n";
}catch(\Throwable $e){echo json_encode(['status'=>'DENIED','error'=>$e->getMessage(),'retry_permitted'=>false],JSON_THROW_ON_ERROR)."\n";exit(42);}
