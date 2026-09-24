<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;

/** Normalizes bounded read-only Etsy evidence into the reconciliation outcome contract. */
final class EtsyReconciliationLookupResponse
{
    public static function normalize(array $plan,mixed $response): array|WP_Error
    {
        if (($plan['state']??'')!=='ETSY_RECONCILIATION_LOOKUP_PLANNED' || ($plan['method']??'')!=='GET') return self::error('plan','Validated lookup plan required.');
        if (!is_array($response)) return ['state'=>EtsyOperationLifecycle::UNKNOWN,'failure_category'=>'transport','failure_code'=>'lookup_no_response','external_reference'=>''];
        $status=(int)($response['status']??0);
        if ($status===404) return ['state'=>EtsyOperationLifecycle::UNKNOWN,'failure_category'=>'provider_lookup','failure_code'=>'listing_not_found_inconclusive','external_reference'=>''];
        if ($status<200||$status>=300) return ['state'=>EtsyOperationLifecycle::UNKNOWN,'failure_category'=>'provider_lookup','failure_code'=>'lookup_http_'.$status,'external_reference'=>''];
        $body=(string)($response['body']??'');
        if (strlen($body)>1048576) return self::error('body','Lookup response exceeds the bounded parser limit.');
        $decoded=json_decode($body,true);
        if(!is_array($decoded)) return ['state'=>EtsyOperationLifecycle::UNKNOWN,'failure_category'=>'provider_lookup','failure_code'=>'lookup_body_invalid','external_reference'=>''];
        $expected=(string)($plan['lookup_reference']??'');
        $operationType=(string)($plan['operation_type']??'');
        if($operationType==='CREATE_DRAFT'||$operationType==='UPDATE_DRAFT'){
            $listingId=trim((string)($decoded['listing_id']??''));
            if ($listingId==='' || !hash_equals($expected,$listingId)) return ['state'=>EtsyOperationLifecycle::UNKNOWN,'failure_category'=>'provider_lookup','failure_code'=>'lookup_identity_mismatch','external_reference'=>''];
            if($operationType==='CREATE_DRAFT') return ['state'=>EtsyOperationLifecycle::CONFIRMED_SUCCESS,'external_reference'=>$listingId];
        } else {
            // Inventory/image endpoints are already bound to the persisted listing
            // identity by the exact prepared GET endpoint; their response shapes do
            // not provide a trustworthy top-level listing_id.
            $listingId=$expected;
        }
        $evidence=is_array($plan['operation_evidence']??null)?$plan['operation_evidence']:[];
        return EtsyOperationSpecificReconciliation::compare($operationType,$evidence,$decoded,$listingId);
    }
    private static function error(string $code,string $message):WP_Error{return new WP_Error('digiforge_etsy_reconciliation_response_'.$code,$message,['status'=>409]);}
}
