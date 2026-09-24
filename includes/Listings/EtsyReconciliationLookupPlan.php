<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;

/** Builds a read-only Etsy listing lookup from persisted reconciliation identity. */
final class EtsyReconciliationLookupPlan
{
    public static function build(array $reconciliation,int $integrationId): array|WP_Error
    {
        if (($reconciliation['state']??'')!=='ETSY_RECONCILIATION_READY'
            || ($reconciliation['provider_lookup_required']??null)!==true
            || ($reconciliation['external_retry_permitted']??null)!==false
            || ($reconciliation['network_request_permitted']??null)!==false) {
            return self::error('reconciliation','A network-disabled reconciliation plan is required.');
        }
        $operationId=(int)($reconciliation['operation_id']??0);
        $reference=trim((string)($reconciliation['lookup_reference']??''));
        if ($operationId<1 || $integrationId<1 || ($reconciliation['lookup_identity_available']??null)!==true
            || !preg_match('/^[1-9][0-9]{0,18}$/',$reference)) {
            return self::error('identity','A persisted numeric Etsy listing identity and integration are required.');
        }
        return [
            'state'=>'ETSY_RECONCILIATION_LOOKUP_PLANNED',
            'operation_id'=>$operationId,
            'integration_id'=>$integrationId,
            'method'=>'GET',
            'endpoint'=>'/application/listings/'.$reference,
            'lookup_reference'=>$reference,
            'provider_lookup_required'=>true,
            'mutation_permitted'=>false,
            'external_retry_permitted'=>false,
            'automatic_retry_permitted'=>false,
            'network_request_permitted'=>false,
            'external_execution_performed'=>false,
        ];
    }
    private static function error(string $code,string $message):WP_Error{return new WP_Error('digiforge_etsy_reconciliation_lookup_'.$code,$message,['status'=>409]);}
}
