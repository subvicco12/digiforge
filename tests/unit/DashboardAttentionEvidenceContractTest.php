<?php
declare(strict_types=1);

$source=file_get_contents(dirname(__DIR__,2).'/includes/Portal/Portal.php');
if($source===false){fwrite(STDERR,"Unable to read Portal.php\n");exit(1);}
$start=strpos($source,'private function dashboard(): void');
$end=strpos($source,'private function attention(): void',$start);
if($start===false||$end===false){fwrite(STDERR,"Dashboard method boundary unavailable.\n");exit(1);}
$dashboard=substr($source,$start,$end-$start);
$summary=strpos($dashboard,'(new AttentionReadModel())->summary()');
$availability=strpos($dashboard,"\$attentionUnavailable=(\$attentionSummary['query_state']??'PARTIAL_UNAVAILABLE')!=='AVAILABLE'");
$pulse=strpos($dashboard,'Stage-F operator pulse');
foreach(['summary'=>$summary,'availability'=>$availability,'pulse'=>$pulse] as $label=>$position){
    if($position===false){fwrite(STDERR,"Missing dashboard attention contract: {$label}\n");exit(1);}
}
if(!($summary<$availability&&$availability<$pulse)){
    fwrite(STDERR,"Dashboard must initialize fail-closed attention evidence before rendering Stage-F pulse.\n");exit(1);
}
foreach(['orders_needing_reconciliation','fulfillment_decisions','open_operational_alerts'] as $key){
    if(!str_contains($dashboard,"\$attentionSummary['{$key}']===null?'UNAVAILABLE'")){
        fwrite(STDERR,"Dashboard does not fail closed for {$key}.\n");exit(1);
    }
}
if(!str_contains($dashboard,'External execution authority</span><b>NO</b>')){
    fwrite(STDERR,"Dashboard Stage-F pulse lost the no-external-execution invariant.\n");exit(1);
}
echo "Dashboard attention evidence contract passed.\n";
