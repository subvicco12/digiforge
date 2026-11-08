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
  $limit=max(1,min(100,$limit));$items=[];$states=['action_scheduler'=>'UNAVAILABLE','wp_cron'=>'UNAVAILABLE'];$truncated=false;$dependency=ActionSchedulerReadiness::inspect();$reason='DEPENDENCY_NOT_READY';$subreason='NONE';
  try{$reason='QUERY_EXCEPTION';$frames=$this->actionReader!==null?($this->actionReader)($limit):($dependency['state']==='AVAILABLE'?$this->actions($limit):null);if($frames!==null&&!is_array($frames))throw new \UnexpectedValueException('RESULT_CONTAINER');if(is_array($frames)){if(count($frames)>6*$limit)throw new \UnexpectedValueException('FRAME_BOUND');$actionItems=[];foreach($frames as $frame){if(!is_array($frame))throw new \UnexpectedValueException('FRAME_SHAPE');if(!is_int($frame['id']??null)||$frame['id']<1)throw new \UnexpectedValueException('ACTION_ID');if(!in_array($frame['state']??null,['PENDING','RUNNING','FAILED'],true))throw new \UnexpectedValueException('ACTION_STATE');if(!is_object($frame['action']??null)||!is_callable([$frame['action'],'get_hook'])||!is_callable([$frame['action'],'get_args']))throw new \UnexpectedValueException('ACTION_METHODS');$meta=$this->metadata($frame['action']->get_hook(),$frame['action']->get_args());if($meta===null)throw new \UnexpectedValueException('TASK_METADATA');$actionItems[]=['scheduler'=>'ACTION_SCHEDULER','action_id'=>$frame['id'],'state'=>$frame['state'],'scheduled_at'=>null]+$meta;}$states['action_scheduler']='AVAILABLE';$reason='QUERY_PASSED';$items=$actionItems;}elseif($dependency['state']==='AVAILABLE'||$this->actionReader!==null){$reason='MALFORMED_RESULT';$subreason='RESULT_CONTAINER';}else{$reason='DEPENDENCY_NOT_READY';}}
  catch(\UnexpectedValueException $e){$states['action_scheduler']='UNAVAILABLE';$reason='MALFORMED_RESULT';$subreason=in_array($e->getMessage(),['RESULT_CONTAINER','FRAME_BOUND','FRAME_SHAPE','ACTION_ID','ACTION_STATE','ACTION_METHODS','TASK_METADATA'],true)?$e->getMessage():'UNCLASSIFIED';}
  catch(\Throwable){$states['action_scheduler']='UNAVAILABLE';$reason='QUERY_EXCEPTION';}
  try{$cron=$this->cronReader!==null?($this->cronReader)():$this->cron();if(is_array($cron)){if(count($cron)>10000)throw new \UnexpectedValueException();$cronItems=[];$visited=0;foreach($cron as $timestamp=>$hooks){if($timestamp==='version')continue;if(!is_array($hooks)||!preg_match('/^[0-9]+$/D',(string)$timestamp))throw new \UnexpectedValueException();foreach(self::HOOKS as $hook){if(!array_key_exists($hook,$hooks))continue;if(!is_array($hooks[$hook]))throw new \UnexpectedValueException();foreach($hooks[$hook]as $event){if(++$visited>10000)throw new \UnexpectedValueException();$meta=is_array($event)?$this->metadata($hook,$event['args']??null):null;if($meta===null)throw new \UnexpectedValueException();$cronItems[]=['scheduler'=>'WP_CRON','action_id'=>null,'state'=>'PENDING','scheduled_at'=>(int)$timestamp]+$meta;if(count($cronItems)>=$limit){$truncated=true;break 3;}}}}$states['wp_cron']='AVAILABLE';$items=array_merge($items,$cronItems);}}
  catch(\Throwable){$states['wp_cron']='UNAVAILABLE';}
  if(count($items)>$limit)$truncated=true;
  return ['items'=>array_slice($items,0,$limit),'query_state'=>$states,'action_scheduler_dependency'=>$dependency,'action_scheduler_query'=>['state'=>$states['action_scheduler'],'reason_code'=>$reason,'subreason_code'=>$subreason],'truncated'=>$truncated,'read_only'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false];
 }
 private function metadata(mixed $hook,mixed $args):?array {
  // Upstream 3.9.3 executes positional values even when stored keys are associative.
  if(is_array($args))$args=array_values($args);
  if(!is_string($hook)||!in_array($hook,self::HOOKS,true)||!is_array($args)||!is_int($args[0]??null)||$args[0]<1||!in_array($args[1]??null,['digital','goods'],true)||!is_string($args[2]??null)||$args[2]===''||strlen($args[2])>191)return null;
  $stage=$hook===self::HOOKS[1]?($args[3]??null):'dispatcher';if(!in_array($stage,['dispatcher','develop','development_poll','manifest_poll','finalize'],true))return null;
  return ['hook'=>$hook,'candidate_id'=>$args[0],'shop'=>$args[1],'stage'=>$stage,'run_key_sha256'=>hash('sha256',$args[2])];
 }
 private function actions(int $limit):?array {
  if(!function_exists('as_get_scheduled_actions')||!class_exists('ActionScheduler_Store'))return null;
  $states=['PENDING'=>\ActionScheduler_Store::STATUS_PENDING,'RUNNING'=>\ActionScheduler_Store::STATUS_RUNNING,'FAILED'=>\ActionScheduler_Store::STATUS_FAILED];$frames=[];
  foreach(self::HOOKS as $hook)foreach($states as $state=>$status){$actions=as_get_scheduled_actions(['hook'=>$hook,'group'=>'digiforge','status'=>$status,'per_page'=>$limit,'orderby'=>'date','order'=>'DESC']);if(!is_array($actions))throw new \UnexpectedValueException('RESULT_CONTAINER');foreach($actions as $id=>$action){if(!preg_match('/^[1-9][0-9]*$/D',(string)$id))throw new \UnexpectedValueException('ACTION_ID');$frames[]=['id'=>(int)$id,'state'=>$state,'action'=>$action];}}
  return $frames;
 }
 private function cron():?array {
  global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s','cron'));if($wpdb->last_error!=='')return null;if($raw===null)return [];
  if(!is_string($raw)||strlen($raw)>4*1024*1024)return null;$cron=is_serialized($raw)?@unserialize($raw,['allowed_classes'=>false]):null;return is_array($cron)?$cron:null;
 }
}
