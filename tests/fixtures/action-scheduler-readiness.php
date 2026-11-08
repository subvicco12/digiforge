<?php
declare(strict_types=1);
// Each scenario runs in a clean PHP process: no WordPress bootstrap or database.
$scenario=$argv[1]??'absent';
$GLOBALS['queries']=0;$GLOBALS['writes']=0;
if($scenario!=='absent')eval('function as_enqueue_async_action(){++$GLOBALS["writes"];throw new RuntimeException("Scheduling forbidden");}');
function wp_schedule_single_event(){++$GLOBALS['writes'];throw new RuntimeException('Scheduling forbidden');}
function add_action(...$args){}
if($scenario==='absent') {
 // Absence must be tested without even an enqueue stub.
} else {
 eval('class ActionScheduler { public static function plugin_path($file){if($GLOBALS["scenario"]==="uninitialized")throw new RuntimeException("early header read");return $GLOBALS["scenario"]==="absent-header"?$GLOBALS["active_header"]."-missing":$GLOBALS["active_header"];} public static function is_initialized(){if($GLOBALS["scenario"]==="throws")throw new RuntimeException();return $GLOBALS["scenario"]!=="uninitialized";} }');
 $active=in_array($scenario,['incompatible','active-unsupported'],true)?'3.1.0':($scenario==='future'?'5.0.0':($scenario==='available-current'?'4.2.0':'3.5.0'));
 $GLOBALS['active_header']=tempnam(sys_get_temp_dir(),'df-as-header-');
 file_put_contents($GLOBALS['active_header'],"<?php\n/**\n * Version: ".($scenario==='unknown-active'?'unknown':$active)."\n */\n");
 register_shutdown_function(static function(){unlink($GLOBALS['active_header']);});
 eval('class ActionScheduler_Versions {public static function instance(){return new self;} public function latest_version(){return $GLOBALS["scenario"]==="incompatible"?"3.1.0":($GLOBALS["scenario"]==="future"?"5.0.0":($GLOBALS["scenario"]==="late-registered"?"3.6.0":($GLOBALS["scenario"]==="available-current"?"4.2.0":"3.5.0")));}}');
 eval('class ActionScheduler_Store {const STATUS_PENDING="pending",STATUS_RUNNING="in-progress",STATUS_FAILED="failed";}');
 eval('function as_get_scheduled_actions($args){++$GLOBALS["queries"];if($GLOBALS["scenario"]==="query-failure")throw new RuntimeException();if($GLOBALS["scenario"]==="malformed")return [1=>new stdClass];if(in_array($GLOBALS["scenario"],["available","available-current"],true)&&$args["hook"]==="digiforge_u3_build_product"&&$args["status"]==="pending")return [12=>new class {function get_hook(){return "digiforge_u3_build_product";}function get_args(){return [9,"goods","secret-run"];}}];return [];}');
}
require dirname(__DIR__,2).'/includes/Queue/ActionSchedulerReadiness.php';
require dirname(__DIR__,2).'/includes/Queue/Scheduler.php';
require dirname(__DIR__,2).'/includes/Queue/ScheduledTaskReadModel.php';
$expected=['available-current'=>'AVAILABLE','absent-header'=>'INCOMPATIBLE','late-registered'=>'INCOMPATIBLE','active-unsupported'=>'INCOMPATIBLE','unknown-active'=>'INCOMPATIBLE','absent'=>'ABSENT','available'=>'AVAILABLE','uninitialized'=>'UNINITIALIZED','incompatible'=>'INCOMPATIBLE','future'=>'INCOMPATIBLE','throws'=>'UNAVAILABLE','query-failure'=>'UNAVAILABLE','malformed'=>'UNAVAILABLE'][$scenario];
$reader=new DigiForge\Queue\ScheduledTaskReadModel(null,static fn()=>[1700000000=>['digiforge_u3_build_product'=>[['args'=>[7,'digital','secret-run']]]]]);
$result=$reader->snapshot();
function check(bool $pass,string $message):void {if(!$pass)throw new RuntimeException($message);}
if($scenario==='active-unsupported')check($result['action_scheduler_dependency']['version']==='3.1.0'&&$result['action_scheduler_dependency']['registered_version']==='3.5.0','actual loaded version evidence');
if($scenario==='late-registered')check($result['action_scheduler_dependency']['version']==='3.5.0'&&$result['action_scheduler_dependency']['registered_version']==='3.6.0','late registration evidence');
check($result['action_scheduler_dependency']['state']===$expected,'dependency state');
check($result['query_state']['action_scheduler']===($expected==='AVAILABLE'?'AVAILABLE':'UNAVAILABLE'),'compatible query state');
check($result['query_state']['wp_cron']==='AVAILABLE','independent WP cron evidence');
check(count($result['items'])===(in_array($scenario,['available','available-current'],true)?2:1),'item count');
check($result['items'][count($result['items'])-1]['scheduler']==='WP_CRON','fallback metadata');
if(in_array($scenario,['available','available-current'],true))check($result['items'][0]['action_id']===12&&$result['items'][0]['candidate_id']===9,'action metadata');
$injected=(new DigiForge\Queue\ScheduledTaskReadModel(static fn()=>[],static fn()=>[]))->snapshot();
check($injected['query_state']['action_scheduler']==='AVAILABLE','injected reader compatibility');
check(!str_contains(json_encode($result),'secret-run'),'payload redaction');
check($result['read_only']&&!$result['retry_permitted']&&!$result['external_execution_authorized'],'read only flags');
(new DigiForge\Queue\Scheduler)->dispatch();
check($GLOBALS['writes']===0,'no scheduling');
check($GLOBALS['queries']===(in_array($scenario,['available','available-current'],true)?6:(in_array($scenario,['query-failure','malformed'],true)?($scenario==='malformed'?6:1):0)),'query guard');
$available=(new DigiForge\Queue\Scheduler)->available();
check($available===in_array($scenario,['available','available-current','query-failure','malformed'],true),'scheduler capability');
echo $scenario." passed\n";
