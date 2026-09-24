<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use WP_Error;

/**
 * Fail-closed local authorization boundary for a future Etsy publish executor.
 * It revalidates durable approval/readiness records and performs no HTTP.
 */
final class EtsyPublishAuthorizationGate
{
    /** @return array<string,mixed>|WP_Error */
    public function authorize(array $prepublish):array|WP_Error
    {
        if(($prepublish['state']??'')!=='ETSY_PREPUBLISH_EVIDENCE_READY'
            || ($prepublish['publish_authorized']??null)!==false
            || ($prepublish['etsy_api_invoked']??null)!==false
            || ($prepublish['external_execution_performed']??null)!==false) {
            return self::error('evidence','Fresh prepublish evidence is required.');
        }
        $listingId=(int)($prepublish['listing_id']??0);
        $intentId=(int)($prepublish['etsy_intent_id']??0);
        $packageId=(int)($prepublish['draft_package_id']??0);
        $readinessHash=strtolower(trim((string)($prepublish['readiness_hash']??'')));
        if($listingId<1||$intentId<1||$packageId<1||$readinessHash==='') return self::error('evidence','Complete scoped prepublish evidence is required.');

        global $wpdb;
        $intent=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::etsy_intents().' WHERE id=%d LIMIT 1',$intentId),ARRAY_A);
        $package=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::etsy_draft_packages().' WHERE id=%d LIMIT 1',$packageId),ARRAY_A);
        $listing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::listings().' WHERE id=%d LIMIT 1',$listingId),ARRAY_A);
        if(!is_array($intent)||!is_array($package)||!is_array($listing)) return self::error('scope','Persisted publish scope is incomplete.');
        if((string)($intent['state']??'')!=='APPROVED_INTENT'
            || (int)($intent['listing_id']??0)!==$listingId
            || (int)($intent['draft_package_id']??0)!==$packageId
            || (int)($package['listing_id']??0)!==$listingId) return self::error('scope','Current intent/package scope is not durably approved for this listing.');
        if((string)($listing['state']??'')!=='APPROVED'
            || (int)($listing['approved_by']??0)<1 || empty($listing['approved_at'])
            || (int)($package['approved_by']??0)<1 || empty($package['approved_at'])) return self::error('approval','Durable authenticated listing and package approvals are required.');

        $current=(new Repository())->readiness($listingId);
        if($current instanceof WP_Error||empty($current['ready'])) return self::error('readiness','Listing is no longer release-ready.');
        $currentHash=strtolower(trim((string)($current['hash']??'')));
        $packageHash=strtolower(trim((string)($package['readiness_hash']??'')));
        if($currentHash===''||$packageHash===''||!hash_equals($currentHash,$packageHash)||!hash_equals($currentHash,$readinessHash)) return self::error('readiness','Current readiness no longer matches the approved package and prepublish evidence.');

        $policy=EtsyExecutionPolicy::evaluate(EtsyExecutionPolicy::OP_PUBLISH,true);
        if(($policy['allowed']??false)!==true) return self::error('policy','Etsy publish remains blocked by runtime policy.');
        if(Settings::safety_locked()
            || Settings::get('activation_authorized',false)!==true
            || Settings::get('automation_armed',false)!==true
            || Settings::get('stop_all',true)!==false
            || Settings::is_enabled('etsy_publish')!==true) return self::error('locked','Etsy publish remains locked by production safety controls.');

        return [
            'state'=>'ETSY_PUBLISH_AUTHORIZATION_READY',
            'listing_id'=>$listingId,
            'etsy_intent_id'=>$intentId,
            'draft_package_id'=>$packageId,
            'readiness_hash'=>$currentHash,
            'listing_approved_by'=>(int)$listing['approved_by'],
            'package_approved_by'=>(int)$package['approved_by'],
            'durable_approval_verified'=>true,
            'publish_authorized'=>true,
            'network_request_permitted'=>false,
            'network_request_attempted'=>false,
            'etsy_api_invoked'=>false,
            'external_execution_performed'=>false,
        ];
    }
    private static function error(string $code,string $message):WP_Error
    {
        return new WP_Error('digiforge_etsy_publish_gate_'.$code,$message,['status'=>409]);
    }
}
