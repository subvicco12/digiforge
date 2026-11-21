<?php
declare(strict_types=1);
/** Disposable filesystem custody for isolated tests only; never packaged or used in production. */
if(!defined('DIGIFORGE_PRINTIFY_ACCEPTANCE_FENCE_DIR')) {
 $dfFenceDirectory=sys_get_temp_dir().'/df-wp-acceptance-fence-'.bin2hex(random_bytes(12));
 if(!mkdir($dfFenceDirectory,0700)||file_put_contents($dfFenceDirectory.'/events',"DFPF1\n".bin2hex(random_bytes(32))."\n")===false||!chmod($dfFenceDirectory.'/events',0600))throw new RuntimeException('Synthetic private fence unavailable');
 if(file_put_contents($dfFenceDirectory.'/dispatch.lock',"DF_DISPATCH_GATE_V1\n")===false||!chmod($dfFenceDirectory.'/dispatch.lock',0600))throw new RuntimeException('Synthetic dispatch gate unavailable');
 $stat=stat($dfFenceDirectory.'/dispatch.lock');if(file_put_contents($dfFenceDirectory.'/dispatch.identity',"DFG1:".hash('sha256',$stat['dev'].':'.$stat['ino'])."\n")===false||!chmod($dfFenceDirectory.'/dispatch.identity',0600))throw new RuntimeException('Synthetic gate identity unavailable');
 define('DIGIFORGE_PRINTIFY_ACCEPTANCE_FENCE_DIR',$dfFenceDirectory);
 register_shutdown_function(static function()use($dfFenceDirectory):void{if(is_file($dfFenceDirectory.'/events'))unlink($dfFenceDirectory.'/events');if(is_file($dfFenceDirectory.'/dispatch.lock'))unlink($dfFenceDirectory.'/dispatch.lock');if(is_file($dfFenceDirectory.'/dispatch.identity'))unlink($dfFenceDirectory.'/dispatch.identity');if(is_dir($dfFenceDirectory))rmdir($dfFenceDirectory);});
}
