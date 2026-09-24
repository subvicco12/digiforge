<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;

/** Normalizes read-only listing evidence into the existing reconciliation outcome contract. */
final class EtsyReconciliationLookupResponse
{
    public static function normalize(array $plan,mixed $response): array|WP_Error
    {
        if (($plan['state']??'')!=='ETSY_RECONCILIATION_LOOKUP_PLANNED' || ($plan['method']??'')!=='GET') return self::error('plan','Validated lookup plan required.');
        if (is_wp_error($response)) return ['state'=>EtsyOperationLifecycle::UNKNOWN,'failure_category'=>'transport','failure_code'=>'lookup_no_response','external_reference'=>''];
        $status=(int)wp_remote_retrieve_response_code($response);
        if ($status===404) return ['state'=>EtsyOperationLifecycle::UNKNOWN,'failure_category'=>'provider_lookup','failure_code'=>'listing_not_found_inconclusive','external_reference'=>''];
        if ($status<200||$status>=300) return ['state'=>EtsyOperationLifecycle::UNKNOWN,'failure_category'=>'provider_lookup','failure_code'=>'lookup_http_'.$status,'external_reference'=>''];
        $body=(string)wp_remote_retrieve_body($response);
        if (strlen($body)>1048576) return self::error('body','Lookup response exceeds the bounded parser limit.');
        $decoded=json_decode($body,true);
        $listingId=is_array($decoded)?trim((string)($decoded['listing_id']??'')):'';
        $expected=(string)($plan['lookup_reference']??'');
        if ($listingId==='' || !hash_equals($expected,$listingId)) return ['state'=>EtsyOperationLifecycle::UNKNOWN,'failure_category'=>'provider_lookup','failure_code'=>'lookup_identity_mismatch','external_reference'=>''];
        return ['state'=>EtsyOperationLifecycle::CONFIRMED_SUCCESS,'external_reference'=>$listingId];
    }
    private static function error(string $code,string $message):WP_Error{return new WP_Error('digiforge_etsy_reconciliation_response_'.$code,$message,['status'=>409]);}
}
