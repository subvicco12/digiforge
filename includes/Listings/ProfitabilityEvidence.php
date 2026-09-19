<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;

/** Pure pre-release unit economics evidence; never moves money or calls a provider. */
final class ProfitabilityEvidence
{
    /** @return array<string,mixed>|WP_Error */
    public static function calculate(mixed $price,mixed $providerCost,mixed $shipping,mixed $platformFees,mixed $otherCost,string $currency):array|WP_Error
    {
        $currency=strtoupper(trim($currency));
        if(!preg_match('/^[A-Z]{3}$/',$currency))return new WP_Error('digiforge_listing_currency','Invalid currency.',['status'=>400]);
        $values=[];foreach(['price'=>$price,'provider_cost'=>$providerCost,'shipping'=>$shipping,'platform_fees'=>$platformFees,'other_cost'=>$otherCost] as $key=>$value){if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<0||(float)$value>1000000)return new WP_Error('digiforge_listing_economics','Invalid listing economics value.',['status'=>400]);$values[$key]=round((float)$value,4);}
        if($values['price']<=0)return new WP_Error('digiforge_listing_price','Listing price must be greater than zero.',['status'=>400]);
        $cost=round($values['provider_cost']+$values['shipping']+$values['platform_fees']+$values['other_cost'],4);
        $profit=round($values['price']-$cost,4);$margin=round(($profit/$values['price'])*100,4);
        return ['state'=>'LISTING_PROFITABILITY_EVIDENCE_CALCULATED','currency'=>$currency,'price'=>$values['price'],'total_cost'=>$cost,'contribution_profit'=>$profit,'margin_percent'=>$margin,'profitable'=>$profit>0,'publish_authorized'=>false,'external_execution_performed'=>false];
    }
}
