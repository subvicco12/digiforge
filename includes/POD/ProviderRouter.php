<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;

/**
 * Provider-neutral routing boundary. This class never performs network I/O.
 * Concrete mutating adapters remain absent until separately certified/authorized.
 */
final class ProviderRouter
{
    /** @var list<string> */
    private const SUPPORTED = ['printify','gelato'];

    /** @return array<string,mixed>|WP_Error */
    public static function prepare(string $provider,string $environment,string $intentType,array $payload):array|WP_Error
    {
        $provider=sanitize_key($provider);
        $environment=sanitize_key($environment);
        $intentType=strtoupper(sanitize_key($intentType));
        if(!in_array($provider,self::SUPPORTED,true))
            return new WP_Error('digiforge_provider_unsupported','Unsupported POD provider.',['status'=>400]);
        if(!in_array($environment,['sandbox','test','production'],true))
            return new WP_Error('digiforge_provider_environment','Invalid provider environment.',['status'=>400]);
        if(!in_array($intentType,['MAP_PRODUCT','MAP_VARIANT','PREPARE_PRINT_AREA','PREPARE_MOCKUP','PREPARE_UPLOAD','PREPARE_ORDER','PREPARE_PERSONALIZATION'],true))
            return new WP_Error('digiforge_provider_intent','Unsupported POD provider intent.',['status'=>400]);
        return ['state'=>'PROVIDER_ROUTE_PREPARED','provider'=>$provider,'environment'=>$environment,'intent_type'=>$intentType,'payload'=>$payload,'adapter_invoked'=>false,'external_execution_performed'=>false];
    }
}
