<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;

/** Pure, non-executing planner for the single Etsy listing activation mutation. */
final class EtsyPublishListingOperation
{
    /** @return array<string,mixed>|WP_Error */
    public static function plan(int $shopId,int $listingId): array|WP_Error
    {
        if($shopId<1||$listingId<1)return new WP_Error('digiforge_etsy_publish_identity','Valid Etsy shop and listing identities are required.',['status'=>400]);
        return [
            'state'=>'ETSY_PUBLISH_OPERATION_PLANNED',
            'operation'=>'PUBLISH_LISTING',
            'method'=>'PATCH',
            'endpoint'=>"/application/shops/{$shopId}/listings/{$listingId}",
            'payload'=>['state'=>'active'],
            'content_type'=>'application/x-www-form-urlencoded',
            'network_request_permitted'=>false,
            'external_execution_performed'=>false,
            'publish_permitted'=>true,
        ];
    }
}
