<?php
// Official woocommerce/action-scheduler tag 3.9.3 functions.php query implementation.
// Source URL https://github.com/woocommerce/action-scheduler/blob/3.9.3/functions.php
// Function SHA256: 16d2502c682bc6f00fe08e3f5fe660abf1dcf1c60f960daf3a5621b2e1feeb0c
// Only dependency/store test doubles; no library initialization or execution.
$scenario=$argv[1]??'associative';$queries=0;$writes=0;
define('OBJECT','OBJECT');define('ARRAY_A','ARRAY_A');
function as_enqueue_async_action(){++$GLOBALS['writes'];throw new RuntimeException('forbidden');}
class ActionScheduler_Store {const STATUS_PENDING='pending',STATUS_RUNNING='in-progress',STATUS_FAILED='failed';}
class ActionScheduler_Versions {static function instance(){return new self;}function latest_version(){return '3.9.3';}}
class ActionScheduler {static function is_initialized(...$a){return true;}static function plugin_path($f){return $GLOBALS['header'];}static function store(){return new Fixture393Store;}}
class Fixture393Store {
 function query_actions($a){++$GLOBALS['queries'];if($GLOBALS['scenario']==='exception')throw new RuntimeException('private');return $GLOBALS['scenario']==='empty'||$a['hook']!==(str_starts_with($GLOBALS['scenario'],'stage-')?'digiforge_u3_build_product_stage':'digiforge_u3_build_product')||$a['status']!=='pending'?[]:[73];}
 function fetch_action($id){if($GLOBALS['scenario']==='missing-methods')return new stdClass;return new Fixture393Action;}
}
class Fixture393Action {
 function get_hook(){return $GLOBALS['scenario']==='wrong-hook'?'private-hook':(str_starts_with($GLOBALS['scenario'],'stage-')?'digiforge_u3_build_product_stage':'digiforge_u3_build_product');}
 function get_args(){return match($GLOBALS['scenario']){'associative'=>['candidate'=>9,'shop'=>'goods','run'=>'private-run'],'legacy-dispatch'=>[9,'digital'],'stage-legacy'=>[9,'goods','private-run'],'stage-current'=>[9,'goods','private-run','finalize'],'stage-unauthorized'=>[9,'goods','private-run','dispatcher'],'bad-args'=>['bad','goods','private-run'],default=>[9,'goods','private-run']};}
}
$header=tempnam(sys_get_temp_dir(),'as393-');file_put_contents($header,"<?php\n/** Version: 3.9.3\n */");register_shutdown_function(static function(){unlink($GLOBALS['header']);});
function as_get_scheduled_actions( $args = array(), $return_format = OBJECT ) {
	if ( ! ActionScheduler::is_initialized( __FUNCTION__ ) ) {
		return array();
	}
	$store = ActionScheduler::store();
	foreach ( array( 'date', 'modified' ) as $key ) {
		if ( isset( $args[ $key ] ) ) {
			$args[ $key ] = as_get_datetime_object( $args[ $key ] );
		}
	}
	$ids = $store->query_actions( $args );

	if ( 'ids' === $return_format || 'int' === $return_format ) {
		return $ids;
	}

	$actions = array();
	foreach ( $ids as $action_id ) {
		$actions[ $action_id ] = $store->fetch_action( $action_id );
	}

	if ( ARRAY_A === $return_format ) {
		foreach ( $actions as $action_id => $action_object ) {
			$actions[ $action_id ] = get_object_vars( $action_object );
		}
	}

	return $actions;
}
$root=getenv('DF_RUNTIME_ROOT')?:dirname(__DIR__,2);
require $root.'/includes/Queue/ActionSchedulerReadiness.php';require $root.'/includes/Queue/ScheduledTaskReadModel.php';
$s=(new DigiForge\Queue\ScheduledTaskReadModel(null,static fn()=>[]))->snapshot();
$valid=in_array($scenario,['associative','positional','empty','legacy-dispatch','stage-legacy','stage-current'],true);
if($s['query_state']['action_scheduler']!==($valid?'AVAILABLE':'UNAVAILABLE'))throw new RuntimeException('upstream 3.9.3 adapter mismatch: '.$scenario);
if($valid&&$scenario!=='empty'&&($s['items'][0]['action_id']??null)!==73)throw new RuntimeException('action ID mapping');
if(!$valid&&$scenario!=='exception') { $expected=$scenario==='missing-methods'?'ACTION_METHODS':'TASK_METADATA'; if($s['action_scheduler_query']['subreason_code']!==$expected)throw new RuntimeException('malformed subreason'); }
if($scenario==='stage-unauthorized'&&$s['action_scheduler_query']['metadata_reason_code']!=='STAGE_NOT_ALLOWED')throw new RuntimeException('unauthorized stage not rejected');
if(str_contains(json_encode($s),'private')||$writes!==0)throw new RuntimeException('sensitive evidence or execution');
echo $scenario." passed\n";
