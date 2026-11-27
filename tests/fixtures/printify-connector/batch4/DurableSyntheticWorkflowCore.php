<?php
declare(strict_types=1);
require_once __DIR__.'/SyntheticWorkflowCore.php';
use DigiForgePrintifyConnector\Preparation\WorkflowCoreInterface;
use DigiForge\Connector\Printify\ShopBinding;
/** Local fault fixture, not a production journal or durability/recovery certification. */
final class DurableSyntheticWorkflowCore implements WorkflowCoreInterface {
 public string $fault='';public function __construct(private string $path){}
 public function version():string{return '1.0.0';}public function workflowVersion():string{return '1.0.0';}
 public function exchange(array $q):array|WP_Error{return new WP_Error('no_dispatch');}
 public function authorizeWorkflow(ShopBinding $s,int $actor,string $action):array|WP_Error{return (new SyntheticWorkflowCore())->authorizeWorkflow($s,$actor,$action);}
 public function withWebhookSecret(ShopBinding $s,callable $verify):mixed{return (new SyntheticWorkflowCore())->withWebhookSecret($s,$verify);}
 private function transaction(callable $f,bool $write):mixed {
  $lock=fopen($this->path.'.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException();
  try{$c=new SyntheticWorkflowCore();if(is_file($this->path))$c->importState(json_decode(file_get_contents($this->path),true,512,JSON_THROW_ON_ERROR));$result=$f($c);if($write){$tmp=$this->path.'.tmp';$h=fopen($tmp,'wb');if(!$h)throw new RuntimeException();chmod($tmp,0600);$json=json_encode($c->exportState(),JSON_THROW_ON_ERROR);if(fwrite($h,$json)!==strlen($json)||!fflush($h)||!fsync($h))throw new RuntimeException();fclose($h);if(!rename($tmp,$this->path))throw new RuntimeException();}return $result;}finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 public function recordWorkflow(array $q):array|WP_Error{$r=$this->transaction(static fn($c)=>$c->recordWorkflow($q),true);return $this->fault==='lost_ack'?new WP_Error('synthetic_lost_ack'):$r;}
 public function inspectWorkflows(ShopBinding $s,int $actor,int $limit):array|WP_Error{return $this->transaction(static fn($c)=>$c->inspectWorkflows($s,$actor,$limit),false);}
}
