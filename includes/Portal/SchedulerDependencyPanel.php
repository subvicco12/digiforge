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
  return '<section class="df-panel"><h2>Action Scheduler dependency state</h2><dl><dt>Classified state</dt><dd>'.esc_html($state).'</dd><dt>Loaded version</dt><dd>'.esc_html($version).'</dd><dt>Registered version</dt><dd>'.esc_html($registered).'</dd><dt>API compatibility boundary</dt><dd>'.esc_html($range).'</dd></dl><p>'.($state==='AVAILABLE'?'Initialized required API/version checks passed.':'Initialized API compatibility is not established; no readiness is inferred.').'</p><p>Optional dependency. Task query evidence and WordPress cron evidence are reported separately. Availability never authorizes scheduling, retries or external execution.</p></section>';
 }
 private static function version(mixed $value):string{return is_string($value)&&strlen($value)<=32&&preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$value)===1?$value:'UNKNOWN';}
}
