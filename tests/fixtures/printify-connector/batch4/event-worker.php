<?php
declare(strict_types=1);
if(!class_exists('WP_Error')){class WP_Error{public function __construct(...$args){}}}
require dirname(__DIR__,3).'/unit/bootstrap.php';require __DIR__.'/../stage34/companion-test-bootstrap.php';require __DIR__.'/DurableSyntheticWorkflowCore.php';
[$s,,,,,,,$secret]=(require __DIR__.'/fixture.php')();$raw=file_get_contents($argv[2]);$w=new DigiForgePrintifyConnector\Preparation\WorkflowCoordinator(new DurableSyntheticWorkflowCore($argv[1]));$r=$w->webhook($s,1,$raw,'sha256='.hash_hmac('sha256',$raw,$secret));echo is_array($r)?$r['disposition']:'DENIED';
