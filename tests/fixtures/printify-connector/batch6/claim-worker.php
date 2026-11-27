<?php
declare(strict_types=1);
if(!class_exists('WP_Error')){class WP_Error{public function __construct(...$args){}}}
require dirname(__DIR__,3).'/unit/bootstrap.php';require __DIR__.'/../stage34/companion-test-bootstrap.php';require __DIR__.'/SyntheticNativeCore.php';require __DIR__.'/../batch2/DurableSyntheticMutationCore.php';
$i=(require __DIR__.'/../batch2/intent.php')();$core=new SyntheticNativeCore(new DurableSyntheticMutationCore($argv[1]));$core->safetyStop=false;$core->externalLock=false;$native=new DigiForgePrintifyConnector\Preparation\NativePortAdapter($core);$f=new DigiForgePrintifyConnector\Preparation\MutationFence(new DigiForgePrintifyConnector\Preparation\VersionedMutationHost($native));echo is_array($f->reserve($i))?'1':'0';
