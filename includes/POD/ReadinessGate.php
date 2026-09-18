<?php

declare(strict_types=1);

namespace DigiForge\POD;

use WP_Error;

/**
 * Final non-executing POD readiness gate. Produces evidence only; it never
 * publishes a listing or submits a provider order.
 */
final class ReadinessGate
{
    /** @return array<string,mixed>|WP_Error */
    public static function assess(array $scope,array $routing,array $qa,array $commercial): array|WP_Error
    {
        $resolved=AdminScopePolicy::resolve($scope);
        if(is_wp_error($resolved)) return $resolved;

        if(($routing['state']??'')!=='QA_READY'||($routing['ready_for_qa']??false)!==true){
            return new WP_Error('digiforge_pod_routing_not_ready','Supplier/template routing must be QA_READY.',['status'=>409]);
        }
        if(($qa['decision']??'')!=='PASS'){
            return new WP_Error('digiforge_pod_qa_not_passed','Production and personalization QA must pass.',['status'=>409]);
        }
        foreach(['artwork','personalization','mockup','geometry'] as $check){
            if(($qa[$check]??false)!==true) return new WP_Error('digiforge_pod_qa_incomplete','QA check '.$check.' must pass.',['status'=>409]);
        }

        $currency=strtoupper(trim((string)($commercial['currency']??'')));
        $price=$commercial['sale_price']??null;$landed=$commercial['landed_cost']??null;
        if(!preg_match('/^[A-Z]{3}$/',$currency)||!is_numeric($price)||!is_numeric($landed)||(float)$price<=0||(float)$landed<0){
            return new WP_Error('digiforge_pod_commercial_invalid','Valid currency, sale price and landed cost evidence are required.',['status'=>400]);
        }
        if((float)$price<=(float)$landed){
            return new WP_Error('digiforge_pod_margin_invalid','Sale price must exceed landed cost before readiness approval.',['status'=>409]);
        }

        $evidence=[
            'business_id'=>$resolved['business_id'],
            'store_id'=>$resolved['store_id'],
            'product_program_id'=>$resolved['product_program_id'],
            'supplier'=>$routing['supplier']['provider']??'',
            'region'=>$routing['supplier']['region']??'',
            'template_id'=>$routing['template']['template_id']??'',
            'template_version'=>$routing['template']['template_version']??0,
            'personalization_engine'=>$routing['personalization_engine']??'',
            'currency'=>$currency,
            'sale_price'=>(float)$price,
            'landed_cost'=>(float)$landed,
            'qa'=>'PASS',
        ];
        $canonical=$evidence;ksort($canonical);
        return [
            'state'=>'READY_FOR_HUMAN_APPROVAL',
            'evidence'=>$evidence,
            'evidence_hash'=>hash('sha256',(string)wp_json_encode($canonical)),
            'publishing_enabled'=>false,
            'order_execution_enabled'=>false,
        ];
    }
}
