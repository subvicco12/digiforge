<?php
declare(strict_types=1);
require_once __DIR__.'/SyntheticMutationCore.php';
use DigiForgePrintifyConnector\Preparation\MutationCoreInterface;
/** Test-only atomic local journal. NOT a production adapter or crash-durability certification. */
final class DurableSyntheticMutationCore implements MutationCoreInterface {
 public string $fault='';
 public function __construct(private string $path){}
 public function version():string{return '1.0.0';}
 public function exchange(array $q):array|WP_Error {
  $lock=fopen($this->path.'.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Synthetic storage unavailable');
  try{$c=new SyntheticMutationCore();if(is_file($this->path))$c->importState(json_decode(file_get_contents($this->path),true,512,JSON_THROW_ON_ERROR));$result=$c->exchange($q);
   if($this->fault==='storage_failure')return new WP_Error('synthetic_storage_failure');
   $tmp=$this->path.'.tmp';$f=fopen($tmp,'wb');if(!$f)throw new RuntimeException();chmod($tmp,0600);$s=json_encode($c->exportState(),JSON_THROW_ON_ERROR);if(fwrite($f,$s)!==strlen($s)||!fflush($f)||!fsync($f))throw new RuntimeException();fclose($f);if(!rename($tmp,$this->path))throw new RuntimeException();
   if(($this->fault==='lost_claim_ack'&&$q['action']==='CLAIM')||($this->fault==='lost_receipt_ack'&&$q['action']==='RECONCILE'))return new WP_Error('synthetic_lost_ack');return $result;
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
}
