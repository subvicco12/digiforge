<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;

/** Local convergence boundary before any future publish-authorization path. */
final class EtsyPrepublishPreparation
{
    /** @return array<string,mixed>|WP_Error */
    public function prepare(int $intentId,int $packageId):array|WP_Error
    {
        $draft=(new EtsyDraftPreparation())->prepare($intentId);if(is_wp_error($draft))return $draft;
        $release=(new ReleaseEvidence())->verify($packageId);if(is_wp_error($release))return $release;
        if((int)$draft['listing_id']!==(int)$release['listing_id'])return new WP_Error('digiforge_etsy_release_mismatch','Etsy intent and release evidence must belong to the same listing.',['status'=>409]);
        return ['state'=>'ETSY_PREPUBLISH_EVIDENCE_READY','listing_id'=>(int)$draft['listing_id'],'etsy_intent_id'=>$intentId,'draft_package_id'=>$packageId,'readiness_hash'=>(string)$release['readiness_hash'],'publish_authorized'=>false,'etsy_api_invoked'=>false,'external_execution_performed'=>false];
    }
}
