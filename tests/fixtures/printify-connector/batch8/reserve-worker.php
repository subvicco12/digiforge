<?php
require __DIR__.'/RecoveryStore.php';
try{$s=new SyntheticRecoveryStore($argv[1].'/state',$argv[1].'/anchor');$s->transition('a','RESERVE',1,100);echo '1';}catch(Throwable){echo '0';}
