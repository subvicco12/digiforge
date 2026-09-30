<?php
declare(strict_types=1);

namespace DigiForge\POD;

use DigiForge\Database\Tables;

/**
 * Bounded Phase-1 Personalized POD operator projection.
 * Read-only: it exposes human-approved package/preflight state and never grants provider execution.
 */
final class PersonalizedPodOperationsReadModel
{
    public function snapshot(int $limit=50): array
    {
        global $wpdb;
        $limit=max(1,min(100,$limit));
        $rows=$wpdb->get_results($wpdb->prepare(
            'SELECT id,order_id,provider_mapping_id,ownership_mapping_id,state,approved_by,approved_at,external_execution_authorized,external_execution_performed,created_at FROM '.Tables::pod_authorization_packages().' ORDER BY id DESC LIMIT %d',
            $limit
        ),ARRAY_A);
        if(!is_array($rows)||!empty($wpdb->last_error)){
            return $this->unavailable();
        }

        $items=[];$counts=[
            'packages'=>0,
            'human_review_required'=>0,
            'preflight_current'=>0,
            'revalidation_required'=>0,
            'evaluation_errors'=>0,
        ];
        foreach($rows as $row){
            $counts['packages']++;
            $id=(int)($row['id']??0);
            $projection=(new ProductionPreflightOperatorReadModel())->project($id);
            if(is_wp_error($projection)){
                $counts['evaluation_errors']++;
                $counts['revalidation_required']++;
                $items[]=[
                    'package_id'=>$id,
                    'order_id'=>(int)($row['order_id']??0),
                    'package_state'=>(string)($row['state']??'UNKNOWN'),
                    'operator_state'=>'EVIDENCE_UNAVAILABLE',
                    'blockers'=>['PREFLIGHT_EVIDENCE_UNAVAILABLE'],
                    'provider_mapping_id'=>(int)($row['provider_mapping_id']??0),
                    'ownership_mapping_id'=>(int)($row['ownership_mapping_id']??0),
                    'external_execution_authorized'=>false,
                    'external_execution_performed'=>false,
                    'retry_permitted'=>false,
                ];
                continue;
            }
            $state=(string)($projection['operator_state']??'REVALIDATION_REQUIRED');
            if($state==='PREFLIGHT_CURRENT')$counts['preflight_current']++;
            elseif($state==='HUMAN_REVIEW_REQUIRED')$counts['human_review_required']++;
            else $counts['revalidation_required']++;
            $items[]=[
                'package_id'=>$id,
                'order_id'=>(int)($projection['order_id']??0),
                'package_state'=>(string)($projection['package_state']??'UNKNOWN'),
                'operator_state'=>$state,
                'blockers'=>array_values(array_map('strval',(array)($projection['blockers']??[]))),
                'provider_mapping_id'=>(int)($row['provider_mapping_id']??0),
                'ownership_mapping_id'=>(int)($row['ownership_mapping_id']??0),
                'external_execution_authorized'=>false,
                'external_execution_performed'=>false,
                'retry_permitted'=>false,
            ];
        }

        return [
            'query_state'=>'AVAILABLE',
            'counts'=>$counts,
            'items'=>$items,
            'bounded_limit'=>$limit,
            'external_execution_authorized'=>false,
            'external_execution_performed'=>false,
            'retry_permitted'=>false,
        ];
    }

    private function unavailable(): array
    {
        return [
            'query_state'=>'UNAVAILABLE',
            'counts'=>[
                'packages'=>null,
                'human_review_required'=>null,
                'preflight_current'=>null,
                'revalidation_required'=>null,
                'evaluation_errors'=>null,
            ],
            'items'=>[],
            'external_execution_authorized'=>false,
            'external_execution_performed'=>false,
            'retry_permitted'=>false,
        ];
    }
}
