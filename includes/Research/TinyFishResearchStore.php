<?php
declare(strict_types=1);
namespace DigiForge\Research;
use DigiForge\Database\Tables;
use DigiForge\Security\Logger;
use DigiForge\Security\ShopAccess;
use WP_Error;

/** Protected local offline ledger. No provider transport or real spending authority. */
final class TinyFishResearchStore
{
 private string $key;
 public function __construct(private TinyFishResearchContract $contract,string $master,private ?\Closure $clock=null){if(strlen($master)!==32||!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt'))throw new \InvalidArgumentException('Independent encryption key and supported backend required.');$this->key=$master;}
 public function __debugInfo():array{return ['offline_only'=>true];}
 public function __serialize():array{throw new \LogicException('Private store cannot be serialized.');}
 public static function storageKey(array $scope):string{return 'digiforge_tinyfish_'.hash('sha256',self::json([$scope['shop']??null,$scope['environment']??null,$scope['account_sha256']??null]));}
 private function now():int{return $this->clock===null?time():($this->clock)();}
 public function create(array $scope,string $request,int $budget,int $concurrent):array|WP_Error{
  try{$checkpoint=$this->contract->start($scope,$this->now());if($budget<1||$budget>1000000000||$concurrent<1||$concurrent>3)throw new \RuntimeException();}catch(\Throwable){return self::error('scope');}
  return $this->transaction($scope,$request,function(?array $state)use($scope,$budget,$concurrent,$checkpoint):array{
   $state??=['budget_limit'=>$budget,'concurrency_limit'=>$concurrent,'total_reserved'=>0,'sessions'=>[],'requests'=>[],'events'=>[]];
   if($state['budget_limit']!==$budget||$state['concurrency_limit']!==$concurrent||count($state['sessions'])>=100||isset($state['sessions'][$scope['session_sha256']]))throw new \RuntimeException();
   $active=array_filter($state['sessions'],fn($s)=>!$s['revoked']&&($s['status']==='UNKNOWN_RECONCILIATION_REQUIRED'||$s['checkpoint']['deadline']>$this->now()));
   if(count($active)>=$concurrent||$state['total_reserved']>$budget-$checkpoint['reserved_usd_micros'])throw new \RuntimeException();
   $state['total_reserved']+=$checkpoint['reserved_usd_micros'];$s=['revision'=>1,'scope_sha256'=>self::digest($scope),'checkpoint'=>$checkpoint,'reserved_usd_micros'=>$checkpoint['reserved_usd_micros'],'status'=>'OPEN','revoked'=>false,'lease_worker'=>null,'lease_expires_at'=>0,'preflight_failures'=>0,'profile_cipher'=>null];$state['sessions'][$scope['session_sha256']]=$s;return [$state,self::publicState($s)];
  },true);
 }
 public function read(array $scope):array|WP_Error{return $this->transaction($scope,null,function(?array $state)use($scope):array{$s=$this->session($state,$scope);return [$state,self::publicState($s)];});}
 public function claim(array $scope,int $revision,string $request,string $worker):array|WP_Error{
  return $this->change($scope,$revision,$request,function(array $s)use($worker):array{if(preg_match('/^[a-f0-9]{64}$/D',$worker)!==1||$s['status']!=='OPEN'||$s['lease_expires_at']>$this->now())throw new \RuntimeException();$s['lease_worker']=$worker;$s['lease_expires_at']=min($this->now()+30,$s['checkpoint']['deadline']);return $s;});
 }
 public function advance(array $scope,int $revision,string $request,string $worker,string $operation,string $url):array|WP_Error{
  return $this->change($scope,$revision,$request,function(array $s)use($worker,$operation,$url):array{$this->lease($s,$worker);$s['checkpoint']=$this->contract->step($s['checkpoint'],$operation,$url,$this->now());$s['status']='UNKNOWN_RECONCILIATION_REQUIRED';return $s;});
 }
 public function retryPreflight(array $scope,int $revision,string $request,string $worker,string $reason):array|WP_Error{
  return $this->change($scope,$revision,$request,function(array $s)use($worker,$reason):array{$this->lease($s,$worker);if(!in_array($reason,['LOCAL_INTERCEPTION_UNAVAILABLE','LOCAL_WORKER_UNAVAILABLE'],true))throw new \RuntimeException();$s['preflight_failures']++;if($s['preflight_failures']>=3)$s['status']='PAUSED';return $s;});
 }
 public function revoke(array $scope,int $revision,string $request):array|WP_Error{
  return $this->change($scope,$revision,$request,static function(array $s):array{$s['revoked']=true;$s['status']='REVOKED';$s['profile_cipher']=null;$s['lease_worker']=null;$s['lease_expires_at']=0;return $s;},false);
 }
 public function saveProfile(array $scope,int $revision,string $request,string $handle):array|WP_Error{
  return $this->change($scope,$revision,$request,function(array $s)use($scope,$handle):array{if(preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$handle)!==1)throw new \RuntimeException();$nonce=random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);$key=hash_hkdf('sha256',$this->key,32,'tinyfish-profile-v1');$s['profile_cipher']=base64_encode($nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($handle,self::digest($scope),$nonce,$key));return $s;});
 }
 public function restoreProfile(array $scope):string|WP_Error{
  $r=$this->transaction($scope,null,function(?array $state)use($scope):array{$s=$this->session($state,$scope);$this->current($s,$scope);$raw=is_string($s['profile_cipher'])?base64_decode($s['profile_cipher'],true):false;$n=SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;if(!is_string($raw)||strlen($raw)<$n+16)throw new \RuntimeException();$plain=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw,$n),self::digest($scope),substr($raw,0,$n),hash_hkdf('sha256',$this->key,32,'tinyfish-profile-v1'));if(!is_string($plain)||!Logger::write('tinyfish_profile_restored',['scope_sha256'=>self::digest($scope),'external_execution_authorized'=>false],'tinyfish_profile_access',hash('sha256',$scope['session_sha256'])))throw new \RuntimeException();return [$state,['private_restore'=>$plain]];});return $r instanceof WP_Error?$r:$r['private_restore'];
 }
 private function lease(array $s,string $worker):void{if($s['status']!=='OPEN'||$s['lease_worker']!==$worker||$s['lease_expires_at']<=$this->now())throw new \RuntimeException();}
 private function current(array $s,array $scope):void{if($s['revoked']||$s['status']!=='OPEN')throw new \RuntimeException();$this->contract->resume($s['checkpoint'],$scope,$this->now());}
 private function session(?array $state,array $scope):array{$s=$state['sessions'][$scope['session_sha256']]??null;if(!is_array($s)||$s['scope_sha256']!==self::digest($scope))throw new \RuntimeException();return $s;}
 private function change(array $scope,int $revision,string $request,callable $fn,bool $current=true):array|WP_Error{
  return $this->transaction($scope,$request,function(?array $state)use($scope,$revision,$fn,$current):array{$s=$this->session($state,$scope);if($s['revision']!==$revision)throw new \RuntimeException();if($current)$this->current($s,$scope);$s=$fn($s);$s['revision']++;$state['sessions'][$scope['session_sha256']]=$s;return [$state,self::publicState($s)];});
 }
 private static function publicState(array $s):array{unset($s['profile_cipher'],$s['lease_worker']);$s['live_execution_authorized']=false;return $s;}
 private function transaction(array $scope,?string $request,callable $fn,bool $create=false):array|WP_Error{
  global $wpdb;$key=self::storageKey($scope);$stream=substr($key,19);$nested=false;$save='df_tf_'.bin2hex(random_bytes(8));$started=false;
  try{
   if(($scope['shop']??null)!=='personalized_pod')throw new \RuntimeException();foreach(['manage_digiforge','manage_digiforge_connections']as $cap)if(is_wp_error(ShopAccess::authorize('personalized_pod',$cap)))throw new \RuntimeException();
   if($request!==null&&preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$request)!==1)throw new \RuntimeException();
   $wpdb->last_error='';foreach([$wpdb->options,Tables::audit_log()]as $table){$engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));if(strtoupper((string)$engine)!=='INNODB'||self::databaseFailed())throw new \RuntimeException();}
   $active=$wpdb->get_var('SELECT @@session.in_transaction');if(self::databaseFailed()||!in_array((string)$active,['0','1'],true))throw new \RuntimeException();$nested=(string)$active==='1';if($wpdb->query($nested?'SAVEPOINT '.$save:'START TRANSACTION')===false)throw new \RuntimeException();$started=true;
   $aclKey='digiforge_shop_access_state_personalized_pod';$acl=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE",$aclKey));$snapshot=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$aclKey));if(!is_string($acl)||$snapshot!==$acl||self::databaseFailed())throw new \RuntimeException();
   foreach(['manage_digiforge','manage_digiforge_connections']as $cap)if(is_wp_error(ShopAccess::authorize('personalized_pod',$cap)))throw new \RuntimeException();
   if($create&&$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,'[]','no')",$key))===false)throw new \RuntimeException();
   $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE",$key));if(!is_string($raw)||self::databaseFailed())throw new \RuntimeException();$state=$raw==='[]'?null:$this->decode($raw);
   $rows=$wpdb->get_results($wpdb->prepare('SELECT context,actor_id,event_type FROM '.Tables::audit_log().' WHERE object_type=%s AND object_id=%s ORDER BY id ASC LIMIT 251','tinyfish_research',$stream),ARRAY_A);if(!is_array($rows)||self::databaseFailed()||count($rows)!==count($state['events']??[]))throw new \RuntimeException();
   foreach($rows as $i=>$row)if($row['event_type']!=='tinyfish_research_changed'||self::json(json_decode($row['context'],true,32,JSON_THROW_ON_ERROR))!==self::json($state['events'][$i])||(int)$row['actor_id']!==$state['events'][$i]['actor_id'])throw new \RuntimeException();
   if($request!==null&&(in_array($request,$state['requests']??[],true)||count($state['events']??[])>=250))throw new \RuntimeException();
   [$next,$result]=$fn($state);
   if($request!==null){$next['requests'][]=$request;$events=$next['events'];$payload=$next;unset($payload['events']);$event=['revision'=>count($events)+1,'actor_id'=>get_current_user_id(),'request_sha256'=>hash('sha256',$request),'state_sha256'=>self::digest($payload),'previous_sha256'=>$events===[]?str_repeat('0',64):$events[array_key_last($events)]['event_sha256'],'at'=>$this->now(),'external_execution_authorized'=>false];$event['event_sha256']=self::digest($event);$next['events'][]=$event;$json=self::json($next);if(strlen($json)>1000000)throw new \RuntimeException();$packed=self::json(['payload'=>$next,'mac'=>hash_hmac('sha256',$json,hash_hkdf('sha256',$this->key,32,'tinyfish-ledger-v1'))]);if(!Logger::write('tinyfish_research_changed',$event,'tinyfish_research',$stream)||$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s",$packed,$key))!==1)throw new \RuntimeException();}
   if($wpdb->query($nested?'RELEASE SAVEPOINT '.$save:'COMMIT')===false)throw new \RuntimeException();$started=false;return $result;
  }catch(\Throwable){if($started){$ok=$wpdb->query($nested?'ROLLBACK TO SAVEPOINT '.$save:'ROLLBACK');if($nested)$ok=$wpdb->query('RELEASE SAVEPOINT '.$save)!==false&&$ok!==false;if($ok===false)return self::error('rollback_uncertain');}if(!Logger::write('tinyfish_research_denied',['reason'=>'local_gate','external_execution_authorized'=>false],'tinyfish_research_denial',hash('sha256',$key)))return self::error('denial_audit');return self::error('denied');}
 }
 /** Reads mutable database state after queries.
  * @phpstan-impure
  */
 private static function databaseFailed():bool{global $wpdb;return $wpdb->last_error!=='';}
 private function decode(string $raw):array{$envelope=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($envelope)||!is_array($envelope['payload']??null)||!is_string($envelope['mac']??null)||!hash_equals(hash_hmac('sha256',self::json($envelope['payload']),hash_hkdf('sha256',$this->key,32,'tinyfish-ledger-v1')),$envelope['mac']))throw new \RuntimeException();return $envelope['payload'];}
 private static function json(array $value):string{return \DigiForge\Orders\Validator::canonicalJson($value);}
 private static function digest(array $value):string{return hash('sha256',self::json($value));}
 private static function error(string $code):WP_Error{return new WP_Error('tinyfish_research_'.$code,'Current offline research evidence required; no external action or retry authorized.',['status'=>409,'external_execution_authorized'=>false,'retry_permitted'=>false]);}
}
