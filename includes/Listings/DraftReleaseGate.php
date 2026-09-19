<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;

/** Combines fresh release evidence with a prepared Etsy draft; never publishes. */
final class DraftReleaseGate
{
    /** @return array<string,mixed>|WP_Error */
    public function prepare(int $intentId,int $packageId):array|WP_Error
    {
        $evidence=(new ReleaseEvidence())->verify($packageId);
        if(is_wp_error($evidence))return $evidence;
        $draft=(new EtsyDraftPreparation())->prepare($intentId);
        if(is_wp_error($draft))return $draft;
        if((int)$draft['listing_id']!==(int)$evidence['listing_id'])return new WP_Error('digiforge_etsy_release_mismatch','Draft intent and release package must belong to the same listing.',['status'=>409]);
        return ['state'=>'ETSY_DRAFT_RELEASE_PREPARED','intent'=>$draft,'evidence'=>$evidence,'publish_authorized'=>false,'etsy_api_invoked'=>false,'external_execution_performed'=>false];
    }
}
