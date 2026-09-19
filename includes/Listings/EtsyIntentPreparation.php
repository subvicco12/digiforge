<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use DigiForge\Database\Tables;
use WP_Error;

/** Read-only bridge from a persisted BLOCKED Etsy intent to a local execution package. */
final class EtsyIntentPreparation
{
    /** @return array<string,mixed>|WP_Error */
    public function prepare(int $intentId):array|WP_Error
    {
        if($intentId<1)return new WP_Error('digiforge_etsy_intent','Valid Etsy intent ID is required.',['status'=>400]);
        global $wpdb;$intent=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::etsy_intents().' WHERE id=%d LIMIT 1',$intentId),ARRAY_A);
        if(!is_array($intent))return new WP_Error('digiforge_etsy_intent_missing','Etsy intent not found.',['status'=>404]);
        if((string)($intent['state']??'')!=='BLOCKED')return new WP_Error('digiforge_etsy_intent_state','Only BLOCKED Etsy intents may be locally prepared.',['status'=>409]);
        $listingId=(int)($intent['listing_id']??0);
        $readiness=(new Repository())->readiness($listingId);
        if(is_wp_error($readiness)||empty($readiness['ready']))return new WP_Error('digiforge_etsy_listing_not_ready','Listing readiness must pass before Etsy preparation.',['status'=>409]);
        $payload=json_decode((string)($intent['input_payload']??'{}'),true);
        if(!is_array($payload))return new WP_Error('digiforge_etsy_payload','Etsy intent payload is invalid.',['status'=>409]);
        return ['state'=>'ETSY_INTENT_PREPARED','etsy_intent_id'=>$intentId,'listing_id'=>$listingId,'environment'=>(string)$intent['environment'],'intent_type'=>(string)$intent['intent_type'],'payload'=>$payload,'readiness_hash'=>(string)$readiness['hash'],'persisted_state'=>'BLOCKED','adapter_invoked'=>false,'external_execution_performed'=>false];
    }
}
