<?php
declare(strict_types=1);
namespace DigiForge\AI;

use DigiForge\Database\Tables;

/** Read-only attributable AI cost KPI projection. */
final class CostKpiReadModel
{
    /** @return array<string,mixed> */
    public function snapshot(string $shop='all'):array
    {
        global $wpdb;$shop=sanitize_key($shop);$where=$shop===''||$shop==='all'?'':$wpdb->prepare(' WHERE shop_key=%s',$shop);
        $totals=$wpdb->get_row('SELECT COALESCE(SUM(quantity),0) quantity,COALESCE(SUM(estimated_cost),0) estimated_cost,COALESCE(SUM(actual_cost),0) actual_cost FROM '.Tables::shop_ai_usage().$where,ARRAY_A)?:[];
        $groups=$wpdb->get_results('SELECT shop_key,workflow,stage,model_key,COALESCE(SUM(quantity),0) quantity,COALESCE(SUM(estimated_cost),0) estimated_cost,COALESCE(SUM(actual_cost),0) actual_cost FROM '.Tables::shop_ai_usage().$where.' GROUP BY shop_key,workflow,stage,model_key ORDER BY actual_cost DESC',ARRAY_A)?:[];
        $quantity=(int)($totals['quantity']??0);$actual=(float)($totals['actual_cost']??0);
        return ['shop'=>$shop===''?'all':$shop,'quantity'=>$quantity,'estimated_cost'=>(float)($totals['estimated_cost']??0),'actual_cost'=>$actual,'cost_per_attributed_unit'=>$quantity>0?round($actual/$quantity,6):0.0,'business_kpis'=>['cost_per_opportunity'=>null,'cost_per_qualified_opportunity'=>null,'cost_per_developed_product'=>null,'cost_per_approved_listing'=>null,'cost_per_sale'=>null,'cost_per_gross_profit'=>null],'business_kpi_status'=>'UNAVAILABLE_WITHOUT_ATTRIBUTABLE_DENOMINATORS','breakdown'=>$groups,'external_execution_performed'=>false];
    }
}
