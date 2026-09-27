<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;

/** Selects only an approved customer-delivery package that is bound to a RELEASE_READY manifest. */
final class EtsyCustomerDownloadSelector
{
    public static function select(array $bundle,array $spec,array $revision): array|WP_Error
    {
        if(($bundle['state']??'')!=='RELEASE_READY'||(int)($bundle['approved_by']??0)<1||trim((string)($bundle['approved_at']??''))==='') return self::error('bundle','A human-approved RELEASE_READY bundle is required.');
        if(($spec['asset_type']??'')!=='product_package'||($spec['asset_key']??'')!=='customer-package'||($spec['format']??'')!=='zip') return self::error('role','Only the explicit customer delivery ZIP package may be selected for Etsy digital delivery.');
        $requirements=is_array($spec['content_requirements']??null)?$spec['content_requirements']:json_decode((string)($spec['content_requirements']??''),true);
        if(!is_array($requirements)||($requirements['group']??'')!=='product'||trim((string)($requirements['filename']??''))==='') return self::error('role','Customer package product-group filename evidence is required.');
        if((int)($revision['asset_spec_id']??0)!==(int)($spec['id']??0)||($revision['state']??'')!=='APPROVED'||($revision['mime_type']??'')!=='application/zip'||(int)($revision['byte_size']??0)<1) return self::error('revision','An approved ZIP revision bound to the customer package spec is required.');
        $revisionId=(int)($revision['id']??0); $hash=strtolower(trim((string)($revision['checksum_sha256']??'')));
        if($revisionId<1||!preg_match('/^[a-f0-9]{64}$/',$hash)) return self::error('revision','Approved customer package revision integrity evidence is required.');
        $manifest=is_array($bundle['manifest']??null)?$bundle['manifest']:json_decode((string)($bundle['manifest']??'[]'),true);
        if(!is_array($manifest)) return self::error('manifest','Release bundle manifest is unreadable.');
        $entry=null;
        foreach($manifest as $candidate) if(is_array($candidate)&&(int)($candidate['asset_spec_id']??0)===(int)$spec['id']&&(int)($candidate['asset_revision_id']??0)===$revisionId&&hash_equals($hash,strtolower((string)($candidate['checksum_sha256']??'')))){$entry=$candidate;break;}
        if($entry===null) return self::error('manifest','Customer package revision/checksum is not bound to the approved release bundle.');
        return ['state'=>'ETSY_CUSTOMER_DOWNLOAD_SELECTED','release_bundle_id'=>(int)($bundle['id']??0),'asset_spec_id'=>(int)$spec['id'],'asset_revision_id'=>$revisionId,'storage_reference'=>(string)($revision['storage_reference']??''),'filename'=>(string)$requirements['filename'],'mime_type'=>'application/zip','byte_size'=>(int)$revision['byte_size'],'checksum_sha256'=>$hash,'manifest_entry'=>$entry,'external_execution_performed'=>false];
    }
    private static function error(string $code,string $message): WP_Error{return new WP_Error('digiforge_etsy_customer_download_'.$code,$message,['status'=>409]);}
}
