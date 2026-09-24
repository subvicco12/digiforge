<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use DigiForge\Core\Settings;
use WP_Error;

/**
 * Fail-closed local authorization boundary for a future Etsy publish executor.
 * It performs no HTTP and never changes activation/runtime controls.
 */
final class EtsyPublishAuthorizationGate
{
    /** @return array<string,mixed>|WP_Error */
    public function authorize(array $prepublish,bool $humanApproved):array|WP_Error
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
        $readinessHash=trim((string)($prepublish['readiness_hash']??''));
        if($listingId<1||$intentId<1||$packageId<1||$readinessHash===''||!$humanApproved) {
            return self::error('approval','Explicit human publish approval and complete evidence are required.');
        }

        $policy=EtsyExecutionPolicy::evaluate(EtsyExecutionPolicy::OP_PUBLISH,true);
        if(($policy['allowed']??false)!==true) {
            return self::error('policy','Etsy publish remains blocked by runtime policy.');
        }
        if(Settings::safety_locked()
            || Settings::get('activation_authorized',false)!==true
            || Settings::get('automation_armed',false)!==true
            || Settings::get('stop_all',true)!==false
            || Settings::is_enabled('etsy_publish')!==true) {
            return self::error('locked','Etsy publish remains locked by production safety controls.');
        }

        return [
            'state'=>'ETSY_PUBLISH_AUTHORIZATION_READY',
            'listing_id'=>$listingId,
            'etsy_intent_id'=>$intentId,
            'draft_package_id'=>$packageId,
            'readiness_hash'=>$readinessHash,
            'human_approval_verified'=>true,
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
