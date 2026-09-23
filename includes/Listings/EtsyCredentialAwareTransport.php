<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Credential-aware Etsy transport boundary.
 *
 * It binds an opaque single-use credential envelope to a validated request
 * plan, but remains network-disabled until a later explicitly authorized
 * transport implementation is introduced.
 */
final class EtsyCredentialAwareTransport
{
    /** @return array<string,mixed>|WP_Error */
    public function prepare(array $requestPlan,EtsyCredentialEnvelope $credential): array|WP_Error
    {
        if (($requestPlan['state']??'')!=='ETSY_HTTP_REQUEST_PLANNED'
            || ($requestPlan['network_request_permitted']??null)!==false
            || ($requestPlan['authorization_header_permitted']??null)!==false
            || ($requestPlan['credentials_exposed']??null)!==false
            || ($requestPlan['external_execution_performed']??null)!==false) {
            return self::error('plan','A validated network-disabled Etsy request plan is required.');
        }

        $operationId=(int)($requestPlan['operation_id']??0);
        if ($operationId<1) {
            return self::error('operation','Valid Etsy operation identity is required.');
        }

        $probe=$credential->consume(
            (int)($requestPlan['integration_id']??0),
            $operationId,
            static function(string $token): array|WP_Error {
                if ($token==='') {
                    return self::error('credential','Non-empty Etsy credential material is required.');
                }
                return [
                    'credential_bound'=>true,
                    'credential_length'=>strlen($token),
                ];
            }
        );
        if (is_wp_error($probe)) return $probe;

        return [
            'state'=>'ETSY_CREDENTIAL_AWARE_TRANSPORT_PREPARED',
            'operation_id'=>$operationId,
            'method'=>$requestPlan['method']??'',
            'endpoint'=>$requestPlan['endpoint']??'',
            'payload_fingerprint'=>$requestPlan['payload_fingerprint']??'',
            'credential_bound'=>($probe['credential_bound']??false)===true,
            'credential_material_returned'=>false,
            'authorization_header_constructed'=>false,
            'network_request_permitted'=>false,
            'network_request_attempted'=>false,
            'adapter_invoked'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private static function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_credential_transport_'.$code,$message,['status'=>409]);
    }
}
