<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Pure planner for the Etsy draft-listing mutation surface.
 * Produces relative endpoints and sanitized payloads only; execution remains
 * behind the controlled transport/interlock/lifecycle pipeline.
 */
final class EtsyDraftListingOperations
{
    /** @return array<string,mixed>|WP_Error */
    public static function create(int $shopId,array $draft): array|WP_Error
    {
        if ($shopId<1) return self::error('shop','A numeric Etsy shop id is required.');
        $required=['quantity','title','description','price','who_made','when_made','taxonomy_id'];
        foreach ($required as $key) if (!array_key_exists($key,$draft)) return self::error('payload','Draft listing payload is incomplete.');
        $payload=self::sanitize($draft);
        if ($payload instanceof WP_Error) return $payload;
        return self::plan('CREATE_DRAFT','POST',"/application/shops/{$shopId}/listings",$payload);
    }

    /** @return array<string,mixed>|WP_Error */
    public static function update(int $shopId,int $listingId,array $changes): array|WP_Error
    {
        if ($shopId<1 || $listingId<1 || $changes===[]) return self::error('identity','Shop, listing and changes are required.');
        $payload=self::sanitize($changes);
        if ($payload instanceof WP_Error) return $payload;
        return self::plan('UPDATE_DRAFT','PUT',"/application/shops/{$shopId}/listings/{$listingId}",$payload);
    }

    /** @return array<string,mixed>|WP_Error */
    public static function inventory(int $listingId,array $products): array|WP_Error
    {
        if ($listingId<1 || $products===[]) return self::error('inventory','Listing id and products are required.');
        return self::plan('UPDATE_INVENTORY','PUT',"/application/listings/{$listingId}/inventory",['products'=>$products]);
    }

    /** @return array<string,mixed>|WP_Error */
    public static function image(int $shopId,int $listingId,int $imageId,int $rank=1): array|WP_Error
    {
        if ($shopId<1 || $listingId<1 || $imageId<1 || $rank<1 || $rank>10) return self::error('image','Valid shop, listing, image and rank are required.');
        // Existing uploaded Etsy image identity only. Binary upload is a separate multipart boundary.
        return self::plan('ATTACH_IMAGE','POST',"/application/shops/{$shopId}/listings/{$listingId}/images",[
            'listing_image_id'=>$imageId,'rank'=>$rank,
        ]);
    }

    /** @return array<string,mixed>|WP_Error */
    private static function sanitize(array $payload): array|WP_Error
    {
        $blocked=['state','is_published','published','active','access_token','refresh_token','authorization'];
        foreach ($blocked as $key) if (array_key_exists($key,$payload)) return self::error('forbidden','Publish state and credential material are forbidden in draft operations.');
        return $payload;
    }

    /** @return array<string,mixed> */
    private static function plan(string $operation,string $method,string $endpoint,array $payload): array
    {
        return [
            'state'=>'ETSY_DRAFT_OPERATION_PLANNED',
            'operation'=>$operation,
            'method'=>$method,
            'endpoint'=>$endpoint,
            'payload'=>$payload,
            'publish_permitted'=>false,
            'network_request_permitted'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private static function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_draft_operation_'.$code,$message,['status'=>409]);
    }
}
