<?php
declare(strict_types=1);
namespace DigiForge\Portal;
/** Format only already-collected bounded evidence. No library calls, reads or scheduling. */
final class SchedulerDependencyPanel {
 public function render(mixed $snapshot):string {
  $d=is_array($snapshot)&&is_array($snapshot['action_scheduler_dependency']??null)?$snapshot['action_scheduler_dependency']:[];
  $states=['ABSENT','UNINITIALIZED','INCOMPATIBLE','UNAVAILABLE','AVAILABLE'];$state=is_string($d['state']??null)&&in_array($d['state'],$states,true)?$d['state']:'UNAVAILABLE';
  $version=self::version($d['version']??null);$registered=self::version($d['registered_version']??null);$minimum=self::version($d['minimum_version']??null);$maximum=$d['maximum_major_exclusive']??null;
  $range=$minimum!=='UNKNOWN'&&is_int($maximum)&&$maximum>0&&$maximum<=100&&version_compare($minimum,(string)$maximum.'.0.0','<')?'>='.$minimum.', <'.$maximum.'.0.0':'UNKNOWN';
  if($state==='AVAILABLE'&&($version==='UNKNOWN'||$registered!==$version||$range==='UNKNOWN'||version_compare($version,$minimum,'<')||version_compare($version,(string)$maximum.'.0.0','>=')))$state='UNAVAILABLE';
  $allowed=['DEPENDENCY_ABSENT','REQUIRED_API_MISSING','STATUS_CONTRACT_INVALID','INITIALIZATION_CHECK','NOT_INITIALIZED','VERSION_REGISTRY_INVALID','LOADED_VERSION_UNVERIFIED','VERSION_MISMATCH','VERSION_UNSUPPORTED','INSPECTION_PASSED','INSPECTION_EXCEPTION','DEPENDENCY_NOT_READY','QUERY_PASSED','QUERY_EXCEPTION','MALFORMED_RESULT'];
  $inspection=is_string($d['reason_code']??null)&&in_array($d['reason_code'],$allowed,true)?$d['reason_code']:'UNVERIFIED';
  $q=is_array($snapshot)&&is_array($snapshot['action_scheduler_query']??null)?$snapshot['action_scheduler_query']:[];
  $query=in_array($q['state']??null,['AVAILABLE','UNAVAILABLE'],true)?$q['state']:'UNAVAILABLE';
  $reason=is_string($q['reason_code']??null)&&in_array($q['reason_code'],$allowed,true)?$q['reason_code']:'UNVERIFIED';
  $sub=is_string($q['subreason_code']??null)&&in_array($q['subreason_code'],['NONE','RESULT_CONTAINER','FRAME_BOUND','FRAME_SHAPE','ACTION_ID','ACTION_STATE','ACTION_METHODS','TASK_METADATA','UNCLASSIFIED'],true)?$q['subreason_code']:'UNVERIFIED';
  return '<section class="df-panel"><h2>Action Scheduler dependency state</h2><dl><dt>Classified state</dt><dd>'.esc_html($state).'</dd><dt>Loaded version</dt><dd>'.esc_html($version).'</dd><dt>Registered version</dt><dd>'.esc_html($registered).'</dd><dt>API compatibility boundary</dt><dd>'.esc_html($range).'</dd><dt>Dependency inspection reason</dt><dd>'.esc_html($inspection).'</dd><dt>Task query readiness</dt><dd>'.esc_html($query).'</dd><dt>Task query reason</dt><dd>'.esc_html($reason).'</dd><dt>Task query subreason</dt><dd>'.esc_html($sub).'</dd></dl><p>'.($state==='AVAILABLE'?'Dependency inspection passed; this does not establish task-query success.':'Initialized API compatibility is not established; no readiness is inferred.').'</p><p>Optional dependency. Task query evidence and WordPress cron evidence are reported separately. Availability never authorizes scheduling, retries or external execution.</p></section>';
 }
 private static function version(mixed $value):string{return is_string($value)&&strlen($value)<=32&&preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$value)===1?$value:'UNKNOWN';}
}
