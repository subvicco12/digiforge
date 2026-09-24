<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;

/** Builds a bounded Etsy multipart image request from a local approved asset. */
final class EtsyMultipartImageRequest
{
    private const MAX_BYTES=10485760;
    private const ALLOWED=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];

    /** @return array<string,mixed>|WP_Error */
    public static function build(int $shopId,int $listingId,string $filePath,int $rank=1): array|WP_Error
    {
        $filePath=wp_normalize_path($filePath);
        if($shopId<1||$listingId<1||$rank<1||$rank>10||$filePath==='')return self::error('identity','Valid Etsy image identity and asset path are required.');
        if(!is_file($filePath)||!is_readable($filePath))return self::error('asset','Approved image asset is unavailable.');
        $size=filesize($filePath);
        if($size===false||$size<1||$size>self::MAX_BYTES)return self::error('size','Image asset is empty or exceeds the bounded upload size.');
        $mime=function_exists('mime_content_type')?(string)mime_content_type($filePath):'';
        if(!isset(self::ALLOWED[$mime]))return self::error('mime','Only JPEG, PNG and WebP image assets are permitted.');
        $hash=hash_file('sha256',$filePath);
        if(!is_string($hash)||$hash==='')return self::error('hash','Image integrity hash could not be computed.');
        return [
            'state'=>'ETSY_MULTIPART_IMAGE_PREPARED',
            'method'=>'POST',
            'endpoint'=>"/application/shops/{$shopId}/listings/{$listingId}/images",
            'rank'=>$rank,
            'field'=>'image',
            'filename'=>sanitize_file_name(basename($filePath)),
            'mime_type'=>$mime,
            'size'=>$size,
            'sha256'=>$hash,
            'file_path'=>$filePath,
            'publish_permitted'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private static function error(string $c,string $m): WP_Error{return new WP_Error('digiforge_etsy_multipart_'.$c,$m,['status'=>409]);}
}
