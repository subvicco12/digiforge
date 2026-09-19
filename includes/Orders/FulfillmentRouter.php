<?php
declare(strict_types=1);
namespace DigiForge\Orders;
use WP_Error;

/**
 * Local fulfillment routing preparation only. No provider or marketplace call.
 */
final class FulfillmentRouter
{
    /** @return array<string,mixed>|WP_Error */
    public static function prepare(string $provider,string $environment,string $intentType,array $payload):array|WP_Error
    {
        $provider=sanitize_key($provider);
        $environment=sanitize_key($environment);
        $intentType=strtoupper(sanitize_key($intentType));
        if(!in_array($provider,['printify','gelato'],true))
            return new WP_Error('digiforge_fulfillment_provider','Unsupported fulfillment provider.',['status'=>400]);
        if(!in_array($environment,['sandbox','test','production'],true))
            return new WP_Error('digiforge_fulfillment_environment','Invalid fulfillment environment.',['status'=>400]);
        if($intentType==='')
            return new WP_Error('digiforge_fulfillment_intent','Fulfillment intent is required.',['status'=>400]);
        return ['state'=>'FULFILLMENT_ROUTE_PREPARED','provider'=>$provider,'environment'=>$environment,'intent_type'=>$intentType,'payload'=>$payload,'adapter_invoked'=>false,'external_execution_performed'=>false];
    }
}
