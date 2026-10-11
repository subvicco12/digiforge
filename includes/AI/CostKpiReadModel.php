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
        $wpdb->last_error='';
        $totals=$wpdb->get_row('SELECT COUNT(DISTINCT currency) currency_count,MIN(currency) currency,COALESCE(SUM(quantity),0) quantity,COALESCE(SUM(estimated_cost),0) estimated_cost,COALESCE(SUM(actual_cost),0) actual_cost FROM '.Tables::shop_ai_usage().$where,ARRAY_A);
        $totalsAvailable=is_array($totals)&&empty($wpdb->last_error);
        $totalsState=$totalsAvailable?'AVAILABLE':'UNAVAILABLE';
        $wpdb->last_error='';
        $groups=$wpdb->get_results('SELECT shop_key,workflow,stage,model_key,currency,COALESCE(SUM(quantity),0) quantity,COALESCE(SUM(estimated_cost),0) estimated_cost,COALESCE(SUM(actual_cost),0) actual_cost FROM '.Tables::shop_ai_usage().$where.' GROUP BY shop_key,workflow,stage,model_key,currency ORDER BY actual_cost DESC',ARRAY_A);
        $groupsAvailable=is_array($groups)&&empty($wpdb->last_error);
        $groupsState=$groupsAvailable?'AVAILABLE':'UNAVAILABLE';
        $queryState=$totalsAvailable&&$groupsAvailable?'AVAILABLE':'UNAVAILABLE';
        $currencyCount=(int)($totals['currency_count']??0);$currency=(string)($totals['currency']??'USD');if($currencyCount>1)$queryState='UNAVAILABLE';
        $commercial=(new CommercialCostKpiReadModel())->snapshot($shop,gmdate('Y-m-01 00:00:00'),gmdate('Y-m-01 00:00:00',strtotime('first day of next month UTC')),$currency);
        if($queryState==='UNAVAILABLE'){$totals=[];$groups=[];}
        $quantity=(int)($totals['quantity']??0);$actual=(float)($totals['actual_cost']??0);
        $business=[];foreach(['opportunity','qualified_opportunity','developed_product','approved_listing','sale']as $metric)$business['cost_per_'.$metric]=$commercial['metrics'][$metric]['cost_per_outcome'];$business['cost_per_gross_profit']=null;
        return ['shop'=>$shop===''?'all':$shop,'query_state'=>$queryState,'query_states'=>['totals'=>$totalsState,'breakdown'=>$groupsState],'currency'=>$currencyCount===1?$currency:null,'currency_state'=>$currencyCount>1?'CURRENCY_CONFLICT':'AVAILABLE','quantity'=>$quantity,'estimated_cost'=>(float)($totals['estimated_cost']??0),'actual_cost'=>$actual,'cost_per_attributed_unit'=>$quantity>0?round($actual/$quantity,6):0.0,'business_kpis'=>$business,'business_kpi_status'=>in_array(null,array_slice($business,0,5),true)?'UNAVAILABLE_WITHOUT_ATTRIBUTABLE_DENOMINATORS':'AVAILABLE','commercial_kpis'=>$commercial,'breakdown'=>$groups,'external_execution_performed'=>false];
    }
}
