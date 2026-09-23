<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Fail-closed authorization boundary for future token acquisition.
 * This contract grants no credential access and performs no refresh or network request.
 */
final class EtsyTokenAcquisitionGate
{
    /** @return array<string,mixed>|WP_Error */
    public static function evaluate(array $metadata,array $invocation): array|WP_Error
    {
        if (($metadata['state']??'')!=='ETSY_TOKEN_METADATA_EVALUATED') {
            return self::error('metadata','Evaluated Etsy token metadata is required.');
        }
        if (($invocation['state']??'')!=='ETSY_ADAPTER_INVOCATION_PLANNED') {
            return self::error('invocation','A validated Etsy adapter invocation plan is required.');
        }

        $permit=$invocation['permit']??null;
        if (!is_array($permit)
            || ($permit['state']??'')!=='ADAPTER_CALL_PERMITTED'
            || ($permit['nonce_consumed']??null)!==true
            || ($permit['external_execution_performed']??null)!==false) {
            return self::error('permit','A consumed pre-execution permit is required.');
        }

        $lifecycle=$metadata['token_lifecycle']??null;
        if (!is_array($lifecycle)) return self::error('lifecycle','Token lifecycle decision is required.');

        $state=(string)($lifecycle['state']??'');
        if ($state!=='ACCESS_TOKEN_CURRENT'
            || ($lifecycle['access_token_usable']??null)!==true
            || ($lifecycle['token_material_exposed']??null)!==false
            || ($lifecycle['token_request_permitted']??null)!==false) {
            return self::error('token_unavailable','Current access-token metadata is required; refresh or reauthorization cannot occur here.');
        }

        return [
            'state'=>'ETSY_TOKEN_ACQUISITION_AUTHORIZED',
            'integration_id'=>(int)($metadata['integration_id']??0),
            'operation_id'=>(int)($invocation['operation_id']??0),
            'credential_name'=>'access_token',
            'credential_retrieval_authorized'=>true,
            'credential_retrieval_performed'=>false,
            'token_material_exposed'=>false,
            'token_refresh_permitted'=>false,
            'token_request_permitted'=>false,
            'network_request_permitted'=>false,
            'adapter_invoked'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private static function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_token_acquisition_'.$code,$message,['status'=>409]);
    }
}
