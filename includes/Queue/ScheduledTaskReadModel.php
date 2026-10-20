<?php
declare(strict_types=1);
namespace DigiForge\Queue;

/** Read existing Product Factory scheduler metadata; never schedule, resume or expose task payloads. */
final class ScheduledTaskReadModel
{
 private const HOOKS=['digiforge_u3_build_product','digiforge_u3_build_product_stage'];
 /** Optional adapters read evidence only and are useful where the scheduler API is unavailable. */
 public function __construct(private ?\Closure $actionReader=null,private ?\Closure $cronReader=null){}
 public function snapshot(int $limit=25):array {
  $limit=max(1,min(100,$limit));$items=[];$states=['action_scheduler'=>'UNAVAILABLE','wp_cron'=>'UNAVAILABLE'];$truncated=false;
  try{$frames=$this->actionReader!==null?($this->actionReader)($limit):$this->actions($limit);if(is_array($frames)){if(count($frames)>6*$limit)throw new \UnexpectedValueException();$actionItems=[];foreach($frames as $frame){if(!is_array($frame)||!is_int($frame['id']??null)||$frame['id']<1||!in_array($frame['state']??null,['PENDING','RUNNING','FAILED'],true)||!is_object($frame['action']??null)||!method_exists($frame['action'],'get_hook')||!method_exists($frame['action'],'get_args'))throw new \UnexpectedValueException();$meta=$this->metadata($frame['action']->get_hook(),$frame['action']->get_args());if($meta===null)throw new \UnexpectedValueException();$actionItems[]=['scheduler'=>'ACTION_SCHEDULER','action_id'=>$frame['id'],'state'=>$frame['state'],'scheduled_at'=>null]+$meta;}$states['action_scheduler']='AVAILABLE';$items=$actionItems;}}
  catch(\Throwable){$states['action_scheduler']='UNAVAILABLE';}
  try{$cron=$this->cronReader!==null?($this->cronReader)():$this->cron();if(is_array($cron)){if(count($cron)>10000)throw new \UnexpectedValueException();$cronItems=[];$visited=0;foreach($cron as $timestamp=>$hooks){if($timestamp==='version')continue;if(!is_array($hooks)||!preg_match('/^[0-9]+$/D',(string)$timestamp))throw new \UnexpectedValueException();foreach(self::HOOKS as $hook){if(!array_key_exists($hook,$hooks))continue;if(!is_array($hooks[$hook]))throw new \UnexpectedValueException();foreach($hooks[$hook]as $event){if(++$visited>10000)throw new \UnexpectedValueException();$meta=is_array($event)?$this->metadata($hook,$event['args']??null):null;if($meta===null)throw new \UnexpectedValueException();$cronItems[]=['scheduler'=>'WP_CRON','action_id'=>null,'state'=>'PENDING','scheduled_at'=>(int)$timestamp]+$meta;if(count($cronItems)>=$limit){$truncated=true;break 3;}}}}$states['wp_cron']='AVAILABLE';$items=array_merge($items,$cronItems);}}
  catch(\Throwable){$states['wp_cron']='UNAVAILABLE';}
  if(count($items)>$limit)$truncated=true;
  return ['items'=>array_slice($items,0,$limit),'query_state'=>$states,'truncated'=>$truncated,'read_only'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false];
 }
 private function metadata(mixed $hook,mixed $args):?array {
  if(!is_string($hook)||!in_array($hook,self::HOOKS,true)||!is_array($args)||!is_int($args[0]??null)||$args[0]<1||!in_array($args[1]??null,['digital','goods'],true)||!is_string($args[2]??null)||$args[2]===''||strlen($args[2])>191)return null;
  $stage=$hook===self::HOOKS[1]?($args[3]??null):'dispatcher';if(!in_array($stage,['dispatcher','develop','development_poll','manifest_poll','finalize'],true))return null;
  return ['hook'=>$hook,'candidate_id'=>$args[0],'shop'=>$args[1],'stage'=>$stage,'run_key_sha256'=>hash('sha256',$args[2])];
 }
 private function actions(int $limit):?array {
  if(!function_exists('as_get_scheduled_actions')||!class_exists('ActionScheduler_Store'))return null;
  $states=['PENDING'=>\ActionScheduler_Store::STATUS_PENDING,'RUNNING'=>\ActionScheduler_Store::STATUS_RUNNING,'FAILED'=>\ActionScheduler_Store::STATUS_FAILED];$frames=[];
  foreach(self::HOOKS as $hook)foreach($states as $state=>$status){$actions=as_get_scheduled_actions(['hook'=>$hook,'group'=>'digiforge','status'=>$status,'per_page'=>$limit,'orderby'=>'date','order'=>'DESC']);if(!is_array($actions))return null;foreach($actions as $id=>$action){if(!preg_match('/^[1-9][0-9]*$/D',(string)$id))return null;$frames[]=['id'=>(int)$id,'state'=>$state,'action'=>$action];}}
  return $frames;
 }
 private function cron():?array {
  global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s','cron'));if($wpdb->last_error!=='')return null;if($raw===null)return [];
  if(!is_string($raw)||strlen($raw)>4*1024*1024)return null;$cron=is_serialized($raw)?@unserialize($raw,['allowed_classes'=>false]):null;return is_array($cron)?$cron:null;
 }
}
