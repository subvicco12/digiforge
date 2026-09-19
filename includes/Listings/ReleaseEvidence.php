<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use DigiForge\Database\Tables;
use WP_Error;

/** Verifies that a draft package still matches current listing readiness. */
final class ReleaseEvidence
{
    /** @return array<string,mixed>|WP_Error */
    public function verify(int $packageId):array|WP_Error
    {
        if($packageId<1)return new WP_Error('digiforge_draft_package','Valid draft package ID is required.',['status'=>400]);
        global $wpdb;$package=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::etsy_draft_packages().' WHERE id=%d LIMIT 1',$packageId),ARRAY_A);
        if(!is_array($package))return new WP_Error('digiforge_draft_package_missing','Etsy draft package not found.',['status'=>404]);
        $listingId=(int)($package['listing_id']??0);$current=(new Repository())->readiness($listingId);
        if(is_wp_error($current)||empty($current['ready']))return new WP_Error('digiforge_listing_not_ready','Current listing readiness does not pass.',['status'=>409]);
        $stored=(string)($package['readiness_hash']??'');$actual=(string)($current['hash']??'');
        if($stored===''||$actual===''||!hash_equals($stored,$actual))return new WP_Error('digiforge_listing_evidence_stale','Draft package readiness evidence is stale.',['status'=>409]);
        return ['state'=>'LISTING_RELEASE_EVIDENCE_VERIFIED','draft_package_id'=>$packageId,'listing_id'=>$listingId,'readiness_hash'=>$actual,'publish_authorized'=>false,'etsy_api_invoked'=>false,'external_execution_performed'=>false];
    }
}
