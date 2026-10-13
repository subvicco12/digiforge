<?php
declare(strict_types=1);

// Explicit isolated profile only: never load the official library into the normal WP cohort.
$runtime=getenv('DIGIFORGE_TEST_ACTION_SCHEDULER_DIR');
if(!is_string($runtime)||$runtime===''||!is_file($runtime.'/action-scheduler.php'))throw new RuntimeException('Official Action Scheduler 3.9.3 runtime is mandatory for this isolated profile.');
$header=(string)file_get_contents($runtime.'/action-scheduler.php');
if(!preg_match('/^ \* Version: 3\.9\.3\s*$/m',$header))throw new RuntimeException('The isolated profile requires the pinned official Action Scheduler 3.9.3 release.');
if(!defined('WP_HTTP_BLOCK_EXTERNAL'))define('WP_HTTP_BLOCK_EXTERNAL',true);
$tests=getenv('WP_TESTS_DIR');if(!is_string($tests)||$tests==='')throw new RuntimeException('WP_TESTS_DIR is required.');
require_once $tests.'/includes/functions.php';
tests_add_filter('muplugins_loaded',static function()use($runtime):void{
    add_filter('pre_http_request',static fn($pre)=>$pre!==false?$pre:new WP_Error('offline_action_scheduler','All actual HTTP is forbidden.'),PHP_INT_MAX);
    // Official documented filter disables only background loopback dispatch.
    // Tests explicitly invoke the official queue runner against actual persisted jobs.
    add_filter('action_scheduler_allow_async_request_runner','__return_false');
    require_once $runtime.'/action-scheduler.php';
},-PHP_INT_MAX);
require dirname(__DIR__,2).'/wordpress/bootstrap.php';
