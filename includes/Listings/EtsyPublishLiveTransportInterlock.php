<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use DigiForge\Core\Settings;
use WP_Error;

/** Final capability-specific interlock for Etsy publication. Performs no HTTP. */
final class EtsyPublishLiveTransportInterlock
{
    /** @return array<string,mixed>|WP_Error */
    public static function authorize(array $prepared):array|WP_Error
    {
        if(($prepared['state']??'')!=='ETSY_CONTROLLED_TRANSPORT_PREPARED'||($prepared['network_request_permitted']??null)!==false||($prepared['external_execution_performed']??null)!==false)
            return self::error('prepared','A valid unused controlled Etsy transport is required.');
        if(Settings::safety_locked()||Settings::get('stop_all',true)!==false||Settings::is_enabled('etsy_publish')!==true)
            return self::error('locked','Etsy Publish is not effectively authorized.');
        $operationId=(int)($prepared['operation_id']??0);$integrationId=(int)($prepared['integration_id']??0);
        $method=(string)($prepared['request_plan']['method']??'');$endpoint=(string)($prepared['request_plan']['endpoint']??'');
        if($operationId<1||$integrationId<1||$method!=='PATCH'||!preg_match('#^/application/shops/[1-9][0-9]*/listings/[1-9][0-9]*$#',$endpoint))
            return self::error('scope','Publish transport identity, method or endpoint is invalid.');
        return ['state'=>'ETSY_LIVE_TRANSPORT_AUTHORIZED','operation_id'=>$operationId,'integration_id'=>$integrationId,'method'=>$method,'endpoint'=>$endpoint,'payload_fingerprint'=>(string)($prepared['request_plan']['payload_fingerprint']??''),'network_request_permitted'=>true,'network_request_attempted'=>false,'sent_transition_permitted'=>false,'external_execution_performed'=>false];
    }
    private static function error(string $c,string $m):WP_Error{return new WP_Error('digiforge_etsy_publish_interlock_'.$c,$m,['status'=>409]);}
}
