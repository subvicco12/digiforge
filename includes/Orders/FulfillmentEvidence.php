<?php
declare(strict_types=1);
namespace DigiForge\Orders;
use WP_Error;

/** Validates immutable local fulfillment evidence before route preparation. */
final class FulfillmentEvidence
{
    /** @return array<string,mixed>|WP_Error */
    public static function validate(array $shipping,array $cost,string $currency):array|WP_Error
    {
        try{$shipping=Validator::structured($shipping);$cost=Validator::structured($cost);$currency=Validator::currency($currency);}
        catch(\InvalidArgumentException $e){return new WP_Error('digiforge_fulfillment_evidence',$e->getMessage(),['status'=>400]);}
        $base=$cost['base_production_cost']??null;$ship=$cost['shipping_estimate']??null;
        if($base===null||$ship===null)
            return new WP_Error('digiforge_fulfillment_economics','Base production cost and shipping estimate are required.',['status'=>409]);
        try{$base=Validator::amount($base);$ship=Validator::amount($ship);}
        catch(\InvalidArgumentException $e){return new WP_Error('digiforge_fulfillment_economics',$e->getMessage(),['status'=>400]);}
        if(trim((string)($shipping['method']??''))==='')
            return new WP_Error('digiforge_fulfillment_shipping','Shipping method evidence is required.',['status'=>409]);
        return ['state'=>'FULFILLMENT_EVIDENCE_VALIDATED','currency'=>$currency,'base_production_cost'=>$base,'shipping_estimate'=>$ship,'shipping'=>$shipping,'cost'=>$cost,'external_execution_performed'=>false];
    }
}
