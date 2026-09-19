<?php
declare(strict_types=1);
namespace DigiForge\Orders;
use WP_Error;

/** Pure orchestration for validated local fulfillment route preparation. */
final class FulfillmentRoutePreparation
{
    /** @return array<string,mixed>|WP_Error */
    public static function prepare(string $provider,string $environment,string $intentType,array $payload,array $shipping,array $cost,string $currency):array|WP_Error
    {
        $evidence=FulfillmentEvidence::validate($shipping,$cost,$currency);
        if(is_wp_error($evidence))return $evidence;
        $route=FulfillmentRouter::prepare($provider,$environment,$intentType,$payload);
        if(is_wp_error($route))return $route;
        return ['state'=>'FULFILLMENT_ROUTE_EVIDENCE_PREPARED','route'=>$route,'evidence'=>$evidence,'adapter_invoked'=>false,'external_execution_performed'=>false];
    }
}
