<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;

/**
 * Fail-closed Printify mutation planner.
 *
 * Produces a sanitized, fingerprinted request plan only. It performs no HTTP,
 * retrieves no credential, and never authorizes production or fulfillment.
 */
final class PrintifyControlledExecutionPlan
{
    /** @return array<string,mixed>|WP_Error */
    public static function prepare(array $route,array $readiness,array $template):array|WP_Error
    {
        if(($route['state']??'')!=='PROVIDER_ROUTE_PREPARED'||($route['provider']??'')!=='printify'||($route['adapter_invoked']??null)!==false||($route['external_execution_performed']??null)!==false)
            return self::error('route','A non-executed Printify provider route is required.');
        if(($readiness['state']??'')!=='READY_FOR_HUMAN_APPROVAL'||($readiness['publishing_enabled']??null)!==false||($readiness['order_execution_enabled']??null)!==false)
            return self::error('readiness','Fail-closed POD readiness evidence is required.');
        if(!isset($readiness['evidence_hash'])||!preg_match('/^[a-f0-9]{64}$/',(string)$readiness['evidence_hash']))
            return self::error('readiness_hash','Valid readiness evidence hash is required.');
        try{$normalized=ProductionTemplateContract::normalize($template);}catch(\Throwable $e){return self::error('template',$e->getMessage());}
        if(($normalized['supplier']??'')!=='printify'||($normalized['template_status']??'')!=='VALIDATED')
            return self::error('template_state','A VALIDATED Printify production template is required.');

        $intent=strtoupper((string)($route['intent_type']??''));
        if(!in_array($intent,['PREPARE_MOCKUP','PREPARE_UPLOAD','PREPARE_ORDER','PREPARE_PERSONALIZATION'],true))
            return self::error('intent','Printify controlled execution supports only approved preparation intents.');

        $payload=is_array($route['payload']??null)?$route['payload']:[];
        $material=[
            'provider'=>'printify','environment'=>(string)($route['environment']??''),
            'intent_type'=>$intent,'readiness_hash'=>(string)$readiness['evidence_hash'],
            'template_fingerprint'=>(string)$normalized['fingerprint'],'payload'=>$payload,
        ];
        $canonical=self::canonicalize($material);
        $encoded=wp_json_encode($canonical);
        if(!is_string($encoded)||strlen($encoded)>262144) return self::error('payload','Printify request evidence is too large.');

        return [
            'state'=>'PRINTIFY_CONTROLLED_EXECUTION_PLANNED',
            'provider'=>'printify','api_preference'=>'V2_FIRST',
            'environment'=>$material['environment'],'intent_type'=>$intent,
            'readiness_hash'=>$material['readiness_hash'],'template_fingerprint'=>$material['template_fingerprint'],
            'request_fingerprint'=>hash('sha256',$encoded),'payload'=>$canonical['payload'],
            'credential_retrieval_permitted'=>false,'network_request_permitted'=>false,
            'order_creation_authorized'=>false,'production_authorized'=>false,
            'fulfillment_authorized'=>false,'external_execution_performed'=>false,
        ];
    }

    private static function canonicalize(array $value):array
    {
        foreach($value as $k=>$v) if(is_array($v)) $value[$k]=self::isList($v)?array_map(static fn($x)=>is_array($x)?self::canonicalize($x):$x,$v):self::canonicalize($v);
        if(!self::isList($value)) ksort($value);
        return $value;
    }
    private static function isList(array $v):bool{return array_keys($v)===range(0,count($v)-1);}
    private static function error(string $code,string $message):WP_Error{return new WP_Error('digiforge_printify_plan_'.$code,$message,['status'=>409]);}
}
