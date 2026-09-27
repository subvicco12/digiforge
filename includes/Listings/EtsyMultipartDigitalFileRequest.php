<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;
/** Builds a digital-file multipart request only from an approved release-bundle manifest entry. */
final class EtsyMultipartDigitalFileRequest
{
    public static function build(int $shopId,int $listingId,string $filePath,int $rank,array $bundle,array $manifestEntry): array|WP_Error
    {
        $filePath=wp_normalize_path($filePath);
        if($shopId<1||$listingId<1||$rank<1||$filePath==='')return self::error('identity','Valid Etsy digital-file identity and asset path are required.');
        if(($bundle['state']??'')!=='RELEASE_READY'||(int)($bundle['approved_by']??0)<1||trim((string)($bundle['approved_at']??''))==='')return self::error('bundle','A human-approved RELEASE_READY bundle is required.');
        $revisionId=(int)($manifestEntry['asset_revision_id']??0);
        $expectedHash=strtolower(trim((string)($manifestEntry['checksum_sha256']??'')));
        if($revisionId<1||!preg_match('/^[a-f0-9]{64}$/',$expectedHash))return self::error('manifest','A valid approved bundle manifest entry is required.');
        $manifest=json_decode((string)($bundle['manifest']??'[]'),true);
        if(!is_array($manifest))return self::error('manifest','Approved release bundle manifest is unreadable.');
        $bound=false;
        foreach($manifest as $entry)if(is_array($entry)&&(int)($entry['asset_revision_id']??0)===$revisionId&&hash_equals($expectedHash,strtolower((string)($entry['checksum_sha256']??'')))){$bound=true;break;}
        if(!$bound)return self::error('manifest','Asset revision/checksum is not bound to the approved release bundle.');
        if(!is_file($filePath)||!is_readable($filePath))return self::error('asset','Approved digital asset is unavailable.');
        $actualHash=hash_file('sha256',$filePath); $size=filesize($filePath);
        if(!is_string($actualHash)||!hash_equals($expectedHash,strtolower($actualHash))||$size===false||$size<1)return self::error('integrity','Digital asset bytes do not match the approved bundle checksum.');
        return ['state'=>'ETSY_MULTIPART_FILE_PREPARED','method'=>'POST','endpoint'=>"/application/shops/{$shopId}/listings/{$listingId}/files",'rank'=>$rank,'field'=>'file','filename'=>sanitize_file_name(basename($filePath)),'mime_type'=>function_exists('mime_content_type')?(string)mime_content_type($filePath):'application/octet-stream','size'=>$size,'sha256'=>$expectedHash,'file_path'=>$filePath,'asset_revision_id'=>$revisionId,'release_bundle_id'=>(int)($bundle['id']??0),'publish_permitted'=>false,'external_execution_performed'=>false];
    }
    private static function error(string $c,string $m): WP_Error{return new WP_Error('digiforge_etsy_digital_file_'.$c,$m,['status'=>409]);}
}
