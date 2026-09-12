<?php
declare(strict_types=1);
namespace DigiForge\AI;

final class RoutingPolicy {
    /** @param list<array<string,mixed>> $models */
    public function choose(array $task,array $models): array|\WP_Error {
        $environment=sanitize_key((string)($task['environment']??'sandbox'));
        $required=array_values(array_filter(array_map('sanitize_key',(array)($task['required_capabilities']??[]))));
        $maxCost=max(0,(float)($task['max_cost_per_1k']??0));
        $maxLatency=max(0,(int)($task['max_latency_ms']??0));
        $quality=(int)($task['min_quality_tier']??0);
        $eligible=[];
        foreach($models as $model){
            if(empty($model['enabled']))continue;
            if(sanitize_key((string)($model['environment']??''))!==$environment)continue;
            if((int)($model['quality_tier']??0)<$quality)continue;
            if($maxCost>0&&(float)($model['cost_per_1k']??0)>$maxCost)continue;
            if($maxLatency>0&&(int)($model['latency_ms']??0)>$maxLatency)continue;
            $caps=array_map('sanitize_key',(array)($model['capabilities']??[]));
            if(array_diff($required,$caps)!==[])continue;
            if(!empty($model['prohibited']))continue;
            $eligible[]=$model;
        }
        usort($eligible,static function(array $a,array $b):int{
            $fa=(int)($a['fallback_order']??PHP_INT_MAX);$fb=(int)($b['fallback_order']??PHP_INT_MAX);
            if($fa!==$fb)return $fa<=>$fb;
            $qa=(int)($a['quality_tier']??0);$qb=(int)($b['quality_tier']??0);
            if($qa!==$qb)return $qb<=>$qa;
            $ca=(float)($a['cost_per_1k']??0);$cb=(float)($b['cost_per_1k']??0);
            if($ca!==$cb)return $ca<=>$cb;
            return strcmp((string)($a['model_key']??''),(string)($b['model_key']??''));
        });
        if($eligible===[])return new \WP_Error('digiforge_ai_no_route',__('No eligible local AI model policy exists.','digiforge'),['status'=>409]);
        $winner=$eligible[0];
        return ['model_id'=>(int)($winner['id']??0),'model_key'=>(string)($winner['model_key']??''),'provider'=>(string)($winner['provider']??''),'environment'=>$environment,'decision_version'=>'v1','eligible_count'=>count($eligible)];
    }
}
