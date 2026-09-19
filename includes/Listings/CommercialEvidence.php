<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;

/** Pure listing-level commercial evidence assessment; no persistence or execution. */
final class CommercialEvidence
{
    /** @return array<string,mixed>|WP_Error */
    public static function assess(float $salePrice,float $landedCost,string $currency):array|WP_Error
    {
        $currency=strtoupper(trim($currency));
        if(!preg_match('/^[A-Z]{3}$/',$currency)||!is_finite($salePrice)||!is_finite($landedCost)||$salePrice<=0||$landedCost<0){
            return new WP_Error('digiforge_listing_commercial_invalid','Valid currency, sale price and landed cost are required.',['status'=>400]);
        }
        if($salePrice<=$landedCost)return new WP_Error('digiforge_listing_margin_invalid','Sale price must exceed landed cost.',['status'=>409]);
        $profit=round($salePrice-$landedCost,4);$margin=round(($profit/$salePrice)*100,4);
        return ['state'=>'COMMERCIAL_EVIDENCE_VALIDATED','currency'=>$currency,'sale_price'=>round($salePrice,4),'landed_cost'=>round($landedCost,4),'contribution_before_fees'=>$profit,'gross_margin_percent'=>$margin,'external_execution_performed'=>false];
    }
}
