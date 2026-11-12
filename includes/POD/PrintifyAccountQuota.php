<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;
/** Shared account admission only. Callers must first verify the approved connection scope. */
final class PrintifyAccountQuota
{
 public static function reserve(array $connectionScope,string $endpoint,string $method,string $correlation,int $now=0):true|WP_Error {
  $now=$now?:time();return self::locked($connectionScope,function($db,string $account)use($endpoint,$method,$correlation,$now){
   if($correlation===''||strlen($correlation)>512)return self::error();
   $audit='df_pq_a_'.$account.'_'.hash('sha256',$correlation);
   if(self::read($db,$audit)!==null)return self::error('printify_quota_attempt_consumed');
   $state=self::read($db,'df_pq_s_'.$account)??['until'=>0,'failures'=>0];
   if(!is_int($state['until']??null)||$state['until']<0||!is_int($state['failures']??null)||$state['failures']<0||$state['failures']>3)throw new \RuntimeException();
   if(($state['until']??0)>$now)return self::error('printify_account_cooldown',(int)$state['until']-$now);
   // Half-ceiling fixed windows ensure any rolling interval stays within the official ceiling.
   $limits=[['global',60,300]];
   if(str_contains($endpoint,'/catalog/'))$limits[]=['catalog',60,50];
   if(strtoupper($method)!=='GET'&&preg_match('~/publish(?:\.json)?(?:\?|$)~',$endpoint))$limits[]=['publish',1800,100];
   $updates=[];foreach($limits as [$kind,$seconds,$ceiling]){
    $name='df_pq_w_'.$account.'_'.$kind.'_'.intdiv($now,$seconds);$row=self::read($db,$name)??['count'=>0];if(!is_int($row['count']??null)||$row['count']<0||$row['count']>$ceiling)throw new \RuntimeException();
    if(($row['count']??0)>=$ceiling)return self::error('printify_account_quota_exhausted',$seconds-($now%$seconds));
    $updates[$name]=['count'=>(int)$row['count']+1];
   }
   foreach($updates as $name=>$row)self::write($db,$name,$row);
   self::write($db,$audit,['reserved_at'=>$now,'observed'=>false]);return true;
  });
 }
 public static function observe(array $scope,mixed $status,array $headers,string $correlation,int $now=0):true|WP_Error {
  $now=$now?:time();return self::locked($scope,function($db,string $account)use($status,$headers,$correlation,$now){
   $audit='df_pq_a_'.$account.'_'.hash('sha256',$correlation);$attempt=self::read($db,$audit);
   if($attempt===null||($attempt['observed']??false))return self::error('printify_quota_observation_invalid');
   $name='df_pq_s_'.$account;$state=self::read($db,$name)??['until'=>0,'failures'=>0];$code=is_int($status)?$status:0;
   if(in_array($code,[401,403],true)){$state['until']=max((int)$state['until'],$now+300);$state['failures']=3;}
   elseif($code===429){$state['until']=max((int)$state['until'],$now+PrintifyApiOutcome::retryAfter($headers,$now));}
   elseif($code===0||$code>=500){$state['failures']=min(3,(int)$state['failures']+1);if($state['failures']>=3)$state['until']=max((int)$state['until'],$now+60);}
   else $state['failures']=0;
   self::write($db,$name,$state);self::write($db,$audit,['reserved_at'=>$attempt['reserved_at'],'observed'=>true,'status'=>$code>=100&&$code<=599?$code:0,'observed_at'=>$now]);return true;
  });
 }
 private static function locked(array $scope,callable $action):true|WP_Error {
  global $wpdb;$reference=$scope['external_account_reference']??null;
  if(!is_string($reference)||trim($reference)===''||strlen($reference)>512||!isset($wpdb))return self::error();
  $account=hash('sha256',$reference);$lock='dfpq:'.substr($account,0,58);$held=false;$started=false;
  try {
   // Never commit, roll back or consume quota within a caller-owned transaction.
   $session=$wpdb->get_row('SELECT @@autocommit AS ac, @@in_transaction AS tx',ARRAY_A);
   if($wpdb->last_error||!is_array($session)||(int)$session['ac']!==1||(int)$session['tx']!==0)throw new \RuntimeException();
   $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$wpdb->options));
   if($wpdb->last_error||strtoupper((string)$engine)!=='INNODB')throw new \RuntimeException();
   $held=(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock))==='1';if(!$held)throw new \RuntimeException();
   if($wpdb->query('START TRANSACTION')===false)throw new \RuntimeException();$started=true;
   $result=$action($wpdb,$account);if($result instanceof WP_Error){$wpdb->query('ROLLBACK');$started=false;return $result;}
   if($wpdb->query('COMMIT')===false)throw new \RuntimeException();$started=false;return true;
  }catch(\Throwable){return self::error();}
  finally{if($started)$wpdb->query('ROLLBACK');if($held)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
 }
 private static function read($db,string $name):?array {
  $raw=$db->get_var($db->prepare("SELECT option_value FROM {$db->options} WHERE option_name=%s",$name));if($db->last_error)throw new \RuntimeException();
  if($raw===null)return null;$value=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($value))throw new \RuntimeException();return $value;
 }
 private static function write($db,string $name,array $value):void {
  $sql=$db->prepare("INSERT INTO {$db->options} (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='no'",$name,json_encode($value,JSON_THROW_ON_ERROR));
  if($db->query($sql)===false||$db->last_error)throw new \RuntimeException();
 }
 private static function error(string $code='printify_account_quota_unavailable',int $delay=60):WP_Error {return new WP_Error($code,'Printify account admission is unavailable.',['status'=>in_array($code,['printify_account_quota_exhausted','printify_account_cooldown'],true)?429:(in_array($code,['printify_quota_attempt_consumed','printify_quota_observation_invalid'],true)?409:503),'retry_after'=>max(1,min(3600,$delay))]);}
}
