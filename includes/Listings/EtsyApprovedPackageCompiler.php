<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;
final class EtsyApprovedPackageCompiler
{
    private const AI_DISCLOSURE='AI-assisted design disclosure: This digital product was created and designed by the seller using AI-assisted tools under the seller’s creative direction, prompts, inputs, editing, and approval.';
    public static function compile(array $package,array $listing,array $classification): array|WP_Error
    {
        if((int)($package['approved_by']??0)<1||trim((string)($package['approved_at']??''))==='') return self::error('approval','Human-approved draft package is required.');
        $readinessHash=strtolower(trim((string)($package['readiness_hash']??'')));
        if(!preg_match('/^[a-f0-9]{64}$/',$readinessHash)) return self::error('readiness','Approved readiness hash is required.');
        $listingId=(int)($listing['id']??0);
        if((int)($package['listing_id']??0)<1||(int)$package['listing_id']!==$listingId||($listing['state']??'')!=='APPROVED') return self::error('listing','Approved listing/package binding is required.');

        $canonical=(string)($package['canonical_payload']??'');
        $payloadHash=strtolower(trim((string)($package['payload_hash']??'')));
        if($canonical===''||!preg_match('/^[a-f0-9]{64}$/',$payloadHash)||!hash_equals($payloadHash,hash('sha256',$canonical))) return self::error('payload_hash','Immutable approved package payload hash verification failed.');
        $approved=json_decode($canonical,true);
        if(!is_array($approved)||!is_array($approved['listing']??null)||!is_array($approved['readiness']??null)) return self::error('canonical_payload','Approved package canonical_payload is invalid.');
        $approvedListing=$approved['listing'];
        if((int)($approvedListing['id']??0)!==$listingId||($approvedListing['state']??'')!=='APPROVED') return self::error('canonical_listing','Canonical approved listing binding is invalid.');
        $canonicalReadiness=$approved['readiness'];
        $canonicalReadinessHash=strtolower(trim((string)($canonicalReadiness['hash']??'')));
        if($canonicalReadinessHash===''||!hash_equals($readinessHash,$canonicalReadinessHash)) return self::error('canonical_readiness','Canonical readiness evidence does not match the approved package.');

        $taxonomyEvidence=$classification['taxonomy_evidence']??null;
        $taxonomy=(int)($classification['taxonomy_id']??0);
        if(!is_array($taxonomyEvidence)||($taxonomyEvidence['verified']??false)!==true||(int)($taxonomyEvidence['taxonomy_id']??0)!==$taxonomy) return self::error('taxonomy','Taxonomy evidence produced by the verified Etsy seller-taxonomy lookup is required.');
        $who=trim((string)($classification['who_made']??'')); $when=trim((string)($classification['when_made']??''));
        $whoAllowed=['i_did','someone_else','collective'];
        $whenAllowed=['made_to_order','2020_2026','2010_2019','2007_2009','before_2007','2000_2006','1990s','1980s','1970s','1960s','1950s','1940s','1930s','1920s','1910s','1900s','1800s','1700s','before_1700'];
        if($taxonomy<1||!in_array($who,$whoAllowed,true)||!in_array($when,$whenAllowed,true)) return self::error('classification','Verified Etsy taxonomy_id and supported who_made/when_made values are required.');
        if(!array_key_exists('quantity',$classification)||(int)$classification['quantity']<1) return self::error('quantity','Explicit positive Etsy quantity is required.');
        $quantity=(int)$classification['quantity'];

        if(($classification['seller_attestation']??false)!==true||$who!=='i_did') return self::error('seller_attestation','Explicit authenticated seller attestation is required for seller-made classification.');
        if(($classification['ai_assisted']??false)!==true||($classification['ai_disclosure_approved']??false)!==true) return self::error('ai_disclosure','Explicit AI-assisted creation and disclosure approval are required.');
        $complianceEvidence=['seller_attestation'=>true,'ai_assisted'=>true,'ai_disclosure_approved'=>true,'who_made'=>$who,'when_made'=>$when,'quantity'=>$quantity,'taxonomy_id'=>$taxonomy,'disclosure'=>self::AI_DISCLOSURE];
        $complianceHash=hash('sha256',wp_json_encode($complianceEvidence,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

        $title=trim((string)($approvedListing['title']??'')); $description=trim((string)($approvedListing['description']??'')); $price=(float)($approvedListing['price_amount']??$approvedListing['price']??0);
        if($title===''||$description===''||$price<=0) return self::error('content','Immutable approved package title, description and positive price are required.');
        $externalDescription=rtrim($description)."\n\n".self::AI_DISCLOSURE;
        return ['quantity'=>$quantity,'title'=>$title,'description'=>$externalDescription,'price'=>number_format($price,2,'.',''),'who_made'=>$who,'when_made'=>$when,'taxonomy_id'=>$taxonomy,'is_supply'=>false,'type'=>'download','_digiforge'=>['listing_id'=>$listingId,'draft_package_id'=>(int)$package['id'],'readiness_hash'=>$readinessHash,'payload_hash'=>$payloadHash,'taxonomy_evidence'=>$taxonomyEvidence,'compliance_evidence'=>$complianceEvidence,'compliance_hash'=>$complianceHash,'approved_description_unchanged'=>true,'compiled_from_approved_package'=>true]];
    }
    private static function error(string $code,string $message): WP_Error { return new WP_Error('digiforge_etsy_package_compiler_'.$code,$message,['status'=>409]); }
}
