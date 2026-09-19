<?php
declare(strict_types=1);
namespace DigiForge\Analytics;
use WP_Error;

/** Local threshold assessment only; never sends notifications or changes controls. */
final class AlertAssessment
{
    /** @return array<string,mixed>|WP_Error */
    public static function assess(string $metric,float $value,float $warning,float $critical):array|WP_Error
    {
        $metric=sanitize_key($metric);
        if($metric===''||!is_finite($value)||!is_finite($warning)||!is_finite($critical)||$critical<$warning){
            return new WP_Error('digiforge_alert_invalid','Valid metric and ordered finite thresholds are required.',['status'=>400]);
        }
        $severity=$value>=$critical?'CRITICAL':($value>=$warning?'WARNING':'OK');
        return ['state'=>'ALERT_ASSESSED','metric'=>$metric,'value'=>$value,'warning_threshold'=>$warning,'critical_threshold'=>$critical,'severity'=>$severity,'notification_sent'=>false,'control_changed'=>false,'external_execution_performed'=>false];
    }
}
