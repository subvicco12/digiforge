<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Pure classifier for future Etsy HTTP outcomes.
 *
 * It receives sanitized transport metadata only. It performs no HTTP request,
 * does not inspect credentials, and cannot retry or mutate the operation ledger.
 */
final class EtsyHttpOutcome
{
    /** @return array<string,mixed>|WP_Error */
    public static function classify(array $response): array|WP_Error
    {
        $transport=strtoupper(trim((string)($response['transport_state']??'')));
        if (!in_array($transport,['RESPONSE_RECEIVED','NO_RESPONSE'],true)) {
            return self::error('transport','Explicit transport state is required.');
        }

        if ($transport==='NO_RESPONSE') {
            return self::outcome('UNKNOWN','TRANSPORT','NO_RESPONSE',true,true);
        }

        $status=(int)($response['http_status']??0);
        if ($status < 100 || $status > 599) return self::error('status','Valid HTTP status is required.');

        if ($status>=200 && $status<300) {
            return self::outcome('RESPONSE_ACCEPTED','NONE','HTTP_'.$status,false,false);
        }
        if ($status===401 || $status===403) {
            return self::outcome('CONFIRMED_FAILURE','AUTHENTICATION','HTTP_'.$status,false,false);
        }
        if ($status===429) {
            return self::outcome('CONFIRMED_FAILURE','RATE_LIMIT','HTTP_429',true,false);
        }
        if ($status===408 || $status===425 || $status===502 || $status===503 || $status===504) {
            return self::outcome('UNKNOWN','TRANSIENT_HTTP','HTTP_'.$status,true,true);
        }
        if ($status>=400 && $status<500) {
            return self::outcome('CONFIRMED_FAILURE','REQUEST','HTTP_'.$status,false,false);
        }
        if ($status>=500) {
            return self::outcome('UNKNOWN','PROVIDER','HTTP_'.$status,true,true);
        }

        return self::outcome('CONFIRMED_FAILURE','UNEXPECTED_HTTP','HTTP_'.$status,false,false);
    }

    /** @return array<string,mixed> */
    private static function outcome(string $state,string $category,string $code,bool $retryCandidate,bool $reconcile): array
    {
        return [
            'state'=>$state,
            'failure_category'=>$category,
            'failure_code'=>$code,
            'retry_candidate'=>$retryCandidate,
            'reconciliation_required'=>$reconcile,
            'automatic_retry_permitted'=>false,
            'response_body_exposed'=>false,
            'credentials_exposed'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private static function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_http_outcome_'.$code,$message,['status'=>409]);
    }
}
