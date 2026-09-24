<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Pure request-plan contract for a future Etsy HTTP transport.
 *
 * This validates only sanitized request metadata and consumed-permit bindings.
 * It cannot perform HTTP, expose credentials, invoke the adapter, or mutate state.
 */
final class EtsyHttpRequestPlan
{
    /** @return array<string,mixed>|WP_Error */
    public static function build(array $invocation,string $method,string $endpoint,array $headers=[],int $integrationId=0): array|WP_Error
    {
        if (($invocation['state']??'')!=='ETSY_ADAPTER_INVOCATION_PLANNED') {
            return self::error('invocation','A validated Etsy adapter invocation plan is required.');
        }
        $operationId=(int)($invocation['operation_id']??0);
        $permit=$invocation['permit']??null;
        if ($operationId<1 || !is_array($permit) || ($permit['state']??'')!=='ADAPTER_CALL_PERMITTED' || ($permit['nonce_consumed']??null)!==true) {
            return self::error('permit','A consumed adapter-call permit is required.');
        }

        $method=strtoupper(trim($method));
        if (!in_array($method,['GET','POST','PUT','DELETE'],true)) {
            return self::error('method','Unsupported Etsy HTTP method.');
        }

        $endpoint=trim($endpoint);
        if ($endpoint==='' || strlen($endpoint)>512 || $endpoint[0]!=='/' || str_contains($endpoint,'://') || str_contains($endpoint,'..')) {
            return self::error('endpoint','Etsy endpoint must be a bounded relative API path.');
        }

        $allowedHeaders=[];
        foreach ($headers as $name=>$value) {
            $name=strtolower(trim((string)$name));
            if (!in_array($name,['accept','content-type','idempotency-key'],true) || !is_string($value) || strlen($value)>191) {
                return self::error('headers','Only bounded non-secret transport headers are permitted.');
            }
            $allowedHeaders[$name]=trim($value);
        }
        ksort($allowedHeaders,SORT_STRING);

        $payload=$invocation['payload']??null;
        if (!is_array($payload)) return self::error('payload','Invocation payload is required.');
        $fingerprint=EtsyRequestFingerprint::fromPayload($payload);
        if (is_wp_error($fingerprint)) return $fingerprint;

        return [
            'state'=>'ETSY_HTTP_REQUEST_PLANNED',
            'operation_id'=>$operationId,
            'integration_id'=>$integrationId,
            'method'=>$method,
            'endpoint'=>$endpoint,
            'headers'=>$allowedHeaders,
            'payload'=>$payload,
            'payload_fingerprint'=>$fingerprint,
            'timeout_seconds'=>15,
            'redirects_permitted'=>false,
            'ssl_verification_required'=>true,
            'authorization_header_permitted'=>false,
            'credentials_exposed'=>false,
            'network_request_permitted'=>false,
            'adapter_invoked'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private static function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_http_request_'.$code,$message,['status'=>409]);
    }
}
