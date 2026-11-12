<?php
declare(strict_types=1);
const ARRAY_A='ARRAY_A';
class WP_Error {public function __construct(public string $code,public string $message,public array $data=[]) {}}
function is_wp_error(mixed $v):bool{return $v instanceof WP_Error;}
function wp_get_session_token():string{return $GLOBALS['budget_session']??'isolated-session';}
$root=dirname(__DIR__,3);
foreach(['POD/PrintifyConnectorReadContract','Connector/Printify/ExternalOperationIntent','Connector/Printify/ReadBudget']as $file)require $root.'/includes/'.$file.'.php';
final class BudgetDb {
 public string $options='wp_options';public string $last_error='';public string $engine='InnoDB';public bool $index=true;public int $active=0;public bool $failCommit=false;public bool $failUpdate=false;public array $rows=[];public array $events=[];private array $snapshot=[];
 public function prepare(string $q,mixed ...$args):string{return json_encode([$q,$args],JSON_THROW_ON_ERROR);}
 public function get_results(string $q,mixed $mode):array{return $this->index?[['Key_name'=>'option_name','Column_name'=>'option_name','Non_unique'=>0,'Seq_in_index'=>1,'Sub_part'=>null]]:[];}
 public function get_var(string $q):mixed {
  if($q==='SELECT @@session.in_transaction')return $this->active;
  [$sql,$args]=json_decode($q,true,512,JSON_THROW_ON_ERROR);
  if(str_contains($sql,'SELECT ENGINE'))return $this->engine;
  if(str_contains($sql,'FOR UPDATE')){$this->events[]='lock';return $this->rows[$args[0]]??null;}
  throw new RuntimeException('Unexpected query');
 }
 public function query(string $q):int|false {
  if($q==='START TRANSACTION'){if($this->active)return false;$this->active=1;$this->snapshot=$this->rows;$this->events[]='begin';return 0;}
  if($q==='COMMIT'){$this->events[]='commit';$this->active=0;return $this->failCommit?false:0;}
  if($q==='ROLLBACK'){$this->rows=$this->snapshot;$this->active=0;$this->events[]='rollback';return 0;}
  [$sql,$args]=json_decode($q,true,512,JSON_THROW_ON_ERROR);
  if(str_starts_with($sql,'INSERT IGNORE')){if(isset($this->rows[$args[0]]))return 0;$this->rows[$args[0]]=$args[1];return 1;}
  if(str_starts_with($sql,'UPDATE')){if($this->failUpdate)return false;$this->rows[$args[1]]=$args[0];return 1;}
  throw new RuntimeException('Unexpected mutation');
 }
}
use DigiForge\Connector\Printify\ExternalOperationIntent;
use DigiForge\Connector\Printify\ReadBudget;
$wpdb=new BudgetDb();$budget=new ReadBudget();$counter=0;
$intent=static function()use(&$counter):ExternalOperationIntent{return new ExternalOperationIntent(1,'personalized_pod',7,'PRODUCTS_READ',[],str_pad(dechex(++$counter),32,'0',STR_PAD_LEFT));};
$scenario=$argv[1];$result=[];
if($scenario==='duplicate'){$i=$intent();$result[]=($budget->reserve($i)===true);$result[]=is_wp_error($budget->reserve($i));$result[]=count($wpdb->rows)===2;}
if($scenario==='minute'){for($n=0;$n<3;$n++)$result[]=($budget->reserve($intent())===true);$result[]=is_wp_error((new ReadBudget())->reserve($intent()));$result[]=count($wpdb->rows)===4;}
if($scenario==='cross_session'){
 for($n=0;$n<3;$n++)$result[]=($budget->reserve($intent())===true);
 $GLOBALS['budget_session']='different-session';
 $other=new ExternalOperationIntent(99,'personalized_pod',7,'PRODUCTS_READ',[],str_repeat('f',32));
 $result[]=is_wp_error((new ReadBudget())->reserve($other));
 $result[]=count($wpdb->rows)===4;
}
if($scenario==='five'){
 $budget->reserve($intent());foreach($wpdb->rows as $key=>$value)if(str_contains($key,'window'))$wpdb->rows[$key]=json_encode(array_fill(0,9,time()-61));
 $result[]=($budget->reserve($intent())===true);$result[]=is_wp_error($budget->reserve($intent()));
}
if(in_array($scenario,['noninnodb','index','nested','commit','rollback'],true)){
 if($scenario==='noninnodb')$wpdb->engine='MyISAM';if($scenario==='index')$wpdb->index=false;if($scenario==='nested')$wpdb->active=1;if($scenario==='commit')$wpdb->failCommit=true;if($scenario==='rollback')$wpdb->failUpdate=true;
 $i=$intent();$result[]=is_wp_error($budget->reserve($i));
 $result[]=in_array($scenario,['commit'],true)?count($wpdb->rows)===2:$wpdb->rows===[];
 if($scenario==='commit'){$wpdb->failCommit=false;$result[]=is_wp_error($budget->reserve($i));}
}
if($scenario==='serialization'){
 // A second caller in an active transaction is denied; independent callers acquire/commit in order.
 $wpdb->active=1;$result[]=is_wp_error((new ReadBudget())->reserve($intent()));$wpdb->active=0;
 for($n=0;$n<3;$n++)$result[]=((new ReadBudget())->reserve($intent())===true);
 $result[]=is_wp_error((new ReadBudget())->reserve($intent()));
 $result[]=array_slice($wpdb->events,0,9)===['begin','lock','commit','begin','lock','commit','begin','lock','commit'];
}
if(in_array($scenario,['host_locked','host_missing','host_wrong_scope','reference_identity','audit'],true)){
 eval('namespace DigiForge\\Core; final class Settings {public static bool $locked=false; public static function is_enabled(string $key):bool{return true;} public static function safety_locked():bool{return self::$locked;}}');
 function get_current_user_id():int{return 1;}
 function current_user_can(string $cap):bool{return true;}
 foreach(['CredentialReference','ShopBinding','HostInterface','NativeHost'] as $file)require $root.'/includes/Connector/Printify/'.$file.'.php';
 $host=new \DigiForge\Connector\Printify\NativeHost();
 // Any accidental SQL is observable; this poison object exposes no database methods.
 $wpdb=new class {public int $calls=0;public function __call(string $name,array $args):mixed{$this->calls++;throw new RuntimeException('Unexpected SQL');}};
 if($scenario==='host_locked')\DigiForge\Core\Settings::$locked=true;
 if($scenario==='audit'){
  eval('namespace DigiForge\\Security; final class Logger {public static array $rows=[];public static function write(string $event,array $receipt,string $type):bool{self::$rows[]=$receipt;return true;}}');
  $receipt=['response_sha256'=>str_repeat('a',64),'request_sha256'=>str_repeat('b',64),'binding_sha256'=>str_repeat('c',64),'observed_at'=>'2026-11-12T12:34:56Z'];
  $result[]=$host->record($receipt);
  $result[]=\DigiForge\Security\Logger::$rows===[$receipt];
  $result[]=$host->record(['response_sha256'=>'secret'])===false;
  $result[]=$host->record(['observed_at'=>'2026-02-30T00:00:00Z'])===false;
  $result[]=count(\DigiForge\Security\Logger::$rows)===1;
 }elseif($scenario==='reference_identity'){
  $reference=new \DigiForge\Connector\Printify\CredentialReference(7);
  $binding=new \DigiForge\Connector\Printify\ShopBinding(2,'personalized_pod',7,'123','production',1,$reference,str_repeat('a',64),time()+300);
  $property=new ReflectionProperty($host,'reserved');$property->setValue($host,[7=>[$intent(),$binding]]);
  $called=false;$value=$host->consume(new \DigiForge\Connector\Printify\CredentialReference(7),function()use(&$called){$called=true;});
  $result[]=is_wp_error($value);$result[]=$called===false;
 }else {
  $i=$scenario==='host_wrong_scope'?new ExternalOperationIntent(1,'personalized_pod',7,'PRODUCTS_READ',[],str_repeat('a',32)):new ExternalOperationIntent(1,'personalized_pod',3,'PRODUCTS_READ',[],str_repeat('b',32));
  $result[]=is_wp_error($host->authorize($i));
 }
 $result[]=$wpdb->calls===0;
}
echo json_encode($result,JSON_THROW_ON_ERROR);
