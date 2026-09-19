<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use WP_Error;

/** Read-only bridge from persisted BLOCKED provider intent to local routing. */
final class ProviderRoutePreparation
{
    /** @return array<string,mixed>|WP_Error */
    public function prepare(int $intentId):array|WP_Error
    {
        if($intentId<1)return new WP_Error('digiforge_provider_intent','Valid provider intent ID is required.',['status'=>400]);
        global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_provider_intents().' WHERE id=%d LIMIT 1',$intentId),ARRAY_A);
        if(!is_array($row))return new WP_Error('digiforge_provider_intent_missing','Provider intent not found.',['status'=>404]);
        if((string)($row['state']??'')!=='BLOCKED')return new WP_Error('digiforge_provider_intent_state','Only BLOCKED provider intents may be locally prepared.',['status'=>409]);
        $payload=json_decode((string)($row['input_payload']??'{}'),true);
        if(!is_array($payload))return new WP_Error('digiforge_provider_payload','Provider intent payload is invalid.',['status'=>409]);
        $route=ProviderRouter::prepare((string)$row['provider'],(string)$row['environment'],(string)$row['intent_type'],$payload);
        if(is_wp_error($route))return $route;
        return $route+['provider_intent_id'=>$intentId,'persisted_state'=>'BLOCKED'];
    }
}
