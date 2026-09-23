<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use DigiForge\Core\Settings;
use WP_Error;

/**
 * Final fail-closed interlock immediately before any future Etsy network call.
 *
 * This class deliberately performs no HTTP. It proves that a prepared transport
 * may cross the network boundary only when production activation is complete,
 * STOP ALL is released, and the Etsy draft capability is effectively enabled.
 */
final class EtsyLiveTransportInterlock
{
    /** @return array<string,mixed>|WP_Error */
    public static function authorize(array $prepared): array|WP_Error
    {
        if (($prepared['state']??'')!=='ETSY_CONTROLLED_TRANSPORT_PREPARED'
            || ($prepared['network_request_permitted']??null)!==false
            || ($prepared['network_request_attempted']??null)!==false
            || ($prepared['external_execution_performed']??null)!==false) {
            return self::error('prepared','A valid unused controlled Etsy transport is required.');
        }

        $operationId=(int)($prepared['operation_id']??0);
        $integrationId=(int)($prepared['integration_id']??0);
        if ($operationId<1 || $integrationId<1) {
            return self::error('identity','Valid operation and integration identities are required.');
        }

        if (Settings::safety_locked()
            || Settings::get('activation_authorized',false)!==true
            || Settings::get('automation_armed',false)!==true
            || Settings::get('stop_all',true)!==false
            || Settings::is_enabled('etsy_draft')!==true) {
            return self::error('locked','Etsy network execution remains locked by production safety controls.');
        }

        return [
            'state'=>'ETSY_LIVE_TRANSPORT_AUTHORIZED',
            'operation_id'=>$operationId,
            'integration_id'=>$integrationId,
            'method'=>(string)($prepared['request_plan']['method']??''),
            'endpoint'=>(string)($prepared['request_plan']['endpoint']??''),
            'payload_fingerprint'=>(string)($prepared['request_plan']['payload_fingerprint']??''),
            'network_request_permitted'=>true,
            'network_request_attempted'=>false,
            'sent_transition_permitted'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private static function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_live_transport_'.$code,$message,['status'=>409]);
    }
}
