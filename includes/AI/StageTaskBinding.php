<?php
declare(strict_types=1);
namespace DigiForge\AI;

/** Resolve only reviewed, existing production task/model identities. */
final class StageTaskBinding
{
 public static function validate(mixed $binding):void {
  if(!is_array($binding)||!is_int($binding['task_id']??null)||$binding['task_id']<1||!is_array($binding['provider_models']??null)||$binding['provider_models']===[])throw new \InvalidArgumentException('task_binding requires an existing positive task_id and provider_models object.');
  foreach($binding['provider_models']as $id=>$model){if(!preg_match('/^[1-9][0-9]*$/D',(string)$id)||!is_string($model)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,190}$/D',$model))throw new \InvalidArgumentException('provider_models requires registered positive model IDs and exact provider model identifiers.');}
 }
 public static function resolve(array $binding,array $task,array $models):array|\WP_Error {
  try{self::validate($binding);}catch(\InvalidArgumentException $e){return new \WP_Error('ai_task_binding_invalid',$e->getMessage());}
  if((int)($task['id']??0)!==$binding['task_id']||($task['environment']??'')!=='production'||($task['status']??'')!=='ACTIVE')return new \WP_Error('ai_task_binding_invalid','An active matching production task is required.');
  $decision=(new RoutingPolicy())->choose($task,$models);if($decision instanceof \WP_Error)return $decision;
  $model=$binding['provider_models'][$decision['model_id']]??null;
  if($decision['provider']!=='openai'||!is_string($model))return new \WP_Error('ai_task_binding_invalid','Selected registered model requires an exact supported provider binding.');
  return $decision+['task_id'=>$binding['task_id'],'provider_model'=>$model,'state'=>'BOUND_REGISTERED_POLICY'];
 }
 public static function load(array $binding):array|\WP_Error {
  try{self::validate($binding);}catch(\InvalidArgumentException $e){return new \WP_Error('ai_task_binding_invalid',$e->getMessage());}
  $repository=new Repository();$task=$repository->find(\DigiForge\Database\Tables::ai_tasks(),$binding['task_id']);if($task instanceof \WP_Error)return $task;if($task===null)return new \WP_Error('ai_task_binding_invalid','Configured task does not exist.');
  global $wpdb;$wpdb->last_error='';$models=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.\DigiForge\Database\Tables::ai_models().' WHERE environment=%s AND enabled=1','production'),ARRAY_A);
  if($wpdb->last_error!==''||!is_array($models))return new \WP_Error('ai_routing_evidence_unavailable','Registered routing model evidence is unavailable.');
  return self::resolve($binding,$task,array_map([$repository,'normalize'],$models));
 }
}
