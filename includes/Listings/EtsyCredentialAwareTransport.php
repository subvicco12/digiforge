<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Credential-aware Etsy transport preparation boundary.
 *
 * It validates that one unused scoped credential capability belongs to the
 * request, but deliberately defers secret retrieval/consumption until after
 * the live transport interlock. This prevents destroying the single-use token
 * before the only component allowed to perform the network attempt can use it.
 */
final class EtsyCredentialAwareTransport
{
    /** @return array<string,mixed>|WP_Error */
    public function prepare(array $requestPlan,array $scope): array|WP_Error
    {
        if (($requestPlan['state']??'')!=='ETSY_HTTP_REQUEST_PLANNED'
            || ($requestPlan['network_request_permitted']??null)!==false
            || ($requestPlan['authorization_header_permitted']??null)!==false
            || ($requestPlan['credentials_exposed']??null)!==false
            || ($requestPlan['external_execution_performed']??null)!==false) {
            return self::error('plan','A validated network-disabled Etsy request plan is required.');
        }

        $operationId=(int)($requestPlan['operation_id']??0);
        $integrationId=(int)($scope['integration_id']??0);
        if ($operationId<1 || $integrationId<1
            || (int)($requestPlan['integration_id']??0)!==$integrationId
            || ($scope['state']??'')!=='ETSY_SCOPED_CREDENTIAL_ACCESS_AUTHORIZED'
            || (int)($scope['operation_id']??0)!==$operationId
            || ($scope['credential_name']??'')!=='access_token'
            || ($scope['single_use']??null)!==true
            || ($scope['credential_retrieval_performed']??null)!==false) {
            return self::error('operation','Valid matching unused Etsy credential scope and operation identity are required.');
        }

        return [
            'state'=>'ETSY_CREDENTIAL_AWARE_TRANSPORT_PREPARED',
            'operation_id'=>$operationId,
            'integration_id'=>$integrationId,
            'method'=>$requestPlan['method']??'',
            'endpoint'=>$requestPlan['endpoint']??'',
            'payload_fingerprint'=>$requestPlan['payload_fingerprint']??'',
            'credential_scope'=>$scope,
            'credential_retrieval_deferred'=>true,
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
