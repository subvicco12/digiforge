<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;
final class EtsyApprovedPackageCompiler
{
    public static function compile(array $package,array $listing,array $classification): array|WP_Error
    {
        if((int)($package['approved_by']??0)<1||trim((string)($package['approved_at']??''))==='') return self::error('approval','Human-approved draft package is required.');
        $readinessHash=strtolower(trim((string)($package['readiness_hash']??'')));
        if(!preg_match('/^[a-f0-9]{64}$/',$readinessHash)) return self::error('readiness','Approved readiness hash is required.');
        if((int)($package['listing_id']??0)<1||(int)$package['listing_id']!==(int)($listing['id']??0)||($listing['state']??'')!=='APPROVED') return self::error('listing','Approved listing/package binding is required.');
        $taxonomy=(int)($classification['taxonomy_id']??0); $who=trim((string)($classification['who_made']??'')); $when=trim((string)($classification['when_made']??''));
        if($taxonomy<1||$who===''||$when==='') return self::error('classification','Verified Etsy taxonomy_id, who_made and when_made are required.');
        $title=trim((string)($listing['title']??'')); $description=trim((string)($listing['description']??'')); $price=(float)($listing['price_amount']??$listing['price']??0);
        if($title===''||$description===''||$price<=0) return self::error('content','Approved listing title, description and positive price are required.');
        return ['quantity'=>max(1,(int)($classification['quantity']??1)),'title'=>$title,'description'=>$description,'price'=>number_format($price,2,'.',''),'who_made'=>$who,'when_made'=>$when,'taxonomy_id'=>$taxonomy,'_digiforge'=>['listing_id'=>(int)$listing['id'],'draft_package_id'=>(int)$package['id'],'readiness_hash'=>$readinessHash,'compiled_from_approved_package'=>true]];
    }
    private static function error(string $code,string $message): WP_Error { return new WP_Error('digiforge_etsy_package_compiler_'.$code,$message,['status'=>409]); }
}
