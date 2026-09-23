<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * One-purpose credential access contract for the controlled Etsy path.
 * It describes a retrieval capability but contains no vault implementation.
 */
final class EtsyScopedCredentialAccess
{
    /** @return array<string,mixed>|WP_Error */
    public static function authorize(array $acquisition,array $invocation): array|WP_Error
    {
        if (($acquisition['state']??'')!=='ETSY_TOKEN_ACQUISITION_AUTHORIZED'
            || ($acquisition['credential_retrieval_authorized']??null)!==true
            || ($acquisition['credential_retrieval_performed']??null)!==false) {
            return self::error('acquisition','Authorized, unused Etsy token acquisition is required.');
        }

        $integrationId=(int)($acquisition['integration_id']??0);
        $operationId=(int)($acquisition['operation_id']??0);
        if ($integrationId<1 || $operationId<1
            || $operationId!==(int)($invocation['operation_id']??0)
            || ($invocation['state']??'')!=='ETSY_ADAPTER_INVOCATION_PLANNED') {
            return self::error('scope','Credential access must be bound to one valid integration and operation.');
        }
        if (($acquisition['credential_name']??'')!=='access_token') {
            return self::error('credential','Only the Etsy access token may be requested.');
        }

        return [
            'state'=>'ETSY_SCOPED_CREDENTIAL_ACCESS_AUTHORIZED',
            'integration_id'=>$integrationId,
            'operation_id'=>$operationId,
            'credential_name'=>'access_token',
            'single_use'=>true,
            'general_credential_access'=>false,
            'refresh_token_access'=>false,
            'credential_persistence_permitted'=>false,
            'credential_logging_permitted'=>false,
            'credential_retrieval_performed'=>false,
            'token_material_exposed'=>false,
            'network_request_permitted'=>false,
            'adapter_invoked'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private static function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_scoped_credential_'.$code,$message,['status'=>409]);
    }
}
