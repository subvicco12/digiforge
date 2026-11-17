<?php
declare(strict_types=1);
namespace DigiForge\AI;
use WP_Error;

/** Metadata projection of owner-verified journal records, not a no-send witness or authorization stamp. */
final class TaskExecutionEvidence
{
 public const VERSION='shop2-task-execution-evidence-v1';
 public static function project(string $kind,array $events):array|WP_Error {
  if(!in_array($kind,['IMAGE','RESEARCH'],true)||!array_is_list($events)||$events===[]||count($events)>($kind==='IMAGE'?16:96))return self::error();
  $last=$events[count($events)-1];$i=$events[0]['identity']??null;$version=$kind==='IMAGE'?'shop2-image-request-v1':'shop2-research-queue-v1';$actor=is_array($i)?($i[$kind==='IMAGE'?'created_by':'actor_id']??null):null;
  if(!is_array($i)||($i['version']??null)!==$version||($i['shop_key']??null)!=='personalized_pod'||!in_array($i['mode']??null,['NETWORK','OFFLINE_SIMULATOR'],true)||!is_int($actor)||$actor<1||!is_array($i['run_context']??null)||!is_string($i['run_context']['run_id']??null)||!is_string($i['run_context']['started_at']??null))return self::error();
  $run=$i['run_context'];if(count($run)!==2||array_diff(array_keys($run),['run_id','started_at'])!==[]||preg_match('/^[a-z0-9_-]{1,100}$/D',$run['run_id'])!==1||preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',$run['started_at'])!==1)return self::error();$date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$run['started_at'],new \DateTimeZone('UTC'));if(!$date||$date->format('Y-m-d H:i:s')!==$run['started_at'])return self::error();
  $contracts=$kind==='IMAGE'?[$i['wire_contract']??null]:($i['contracts']??null);if(!is_array($contracts)||!array_is_list($contracts)||count($contracts)<1||count($contracts)>5)return self::error();$requests=[];
  foreach($contracts as $index=>$c){$policy=$kind==='IMAGE'?($i['policy_sha256']??null):($c['policy_sha256']??null);if(!is_array($c)||!ImageGenerationEvidenceStore::hash($c['request_sha256']??null)||!ImageGenerationEvidenceStore::hash($policy)||!is_string($c['model']??null)||$c['model']===''||strlen($c['model'])>100)return self::error();$requests[]=['index'=>$index,'request_sha256'=>$c['request_sha256'],'provider_model'=>$c['model'],'policy_sha256'=>$policy];}
  $projection=[];$dispatch=false;
  foreach($events as $n=>$e){if(!is_int($e['revision']??null)||$e['revision']!==$n||!ImageGenerationEvidenceStore::hash($e['event_sha256']??null)||!is_string($e['state']??null)||!is_int($e['usage_id']??null)||$e['usage_id']<0||!is_string($e['recorded_at']??null))return self::error();$dispatch=$dispatch||in_array($e['state'],['DISPATCHING','POLLING'],true);$projection[]=['revision'=>$n,'state'=>$e['state'],'usage_id'=>$e['usage_id'],'index'=>$kind==='RESEARCH'?($e['index']??null):null,'event_sha256'=>$e['event_sha256'],'previous_event_sha256'=>$e['previous_event_sha256'],'recorded_at'=>$e['recorded_at']];}
  $out=['version'=>self::VERSION,'scope'=>'LOCAL_VERIFIED_JOURNAL_SNAPSHOT','kind'=>$kind,'job_id'=>$last['id'],'shop_key'=>'personalized_pod','actor_id'=>$actor,'mode'=>$i['mode'],'run_context'=>['run_id'=>$run['run_id'],'started_at'=>$run['started_at']],'identity_sha256'=>ImageGenerationEvidenceStore::digest($i),'requests'=>$requests,'state'=>$last['state'],'latest_event_sha256'=>$last['event_sha256'],'events'=>$projection,'event_usage_semantics'=>'RECORDED_STAGE_VALUES_NOT_NEW_USAGE_BINDINGS','dispatch_stage_recorded'=>$dispatch,'local_terminal_cancellation'=>$last['state']==='CANCELLED','execution_fenced'=>false,'cross_root_no_send_verified'=>false,'provider_charge_finality'=>'NOT_ESTABLISHED_BY_EXECUTION_HISTORY','reservation_release_authorized'=>false];
  $out['sha256']=ImageGenerationEvidenceStore::digest($out);return $out;
 }
 private static function error():WP_Error{return new WP_Error('task_execution_evidence_invalid','A current owner-verified journal snapshot is required. History is not provider finality, an execution fence or reservation-release authority.',['status'=>409,'reservation_release_authorized'=>false]);}
}
