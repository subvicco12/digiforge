<?php
declare(strict_types=1);
namespace DigiForge\Analytics;
use WP_Error;

/** Pure, local KPI snapshot calculation. No persistence, network, or automation. */
final class KpiSnapshot
{
    /** @return array<string,mixed>|WP_Error */
    public static function calculate(array $input):array|WP_Error
    {
        foreach(['revenue','cost','orders','units'] as $key){
            if(!array_key_exists($key,$input)||!is_numeric($input[$key])||!is_finite((float)$input[$key])||(float)$input[$key]<0){
                return new WP_Error('digiforge_kpi_invalid','Revenue, cost, orders and units must be finite non-negative numbers.',['status'=>400]);
            }
        }
        $revenue=(float)$input['revenue'];$cost=(float)$input['cost'];$orders=(int)$input['orders'];$units=(int)$input['units'];
        $profit=round($revenue-$cost,4);$margin=$revenue>0?round(($profit/$revenue)*100,4):0.0;$aov=$orders>0?round($revenue/$orders,4):0.0;
        return ['state'=>'KPI_SNAPSHOT_CALCULATED','revenue'=>round($revenue,4),'cost'=>round($cost,4),'profit'=>$profit,'gross_margin_percent'=>$margin,'orders'=>$orders,'units'=>$units,'average_order_value'=>$aov,'external_execution_performed'=>false];
    }
}
