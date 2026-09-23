<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Pure token-lifecycle decision contract for the controlled Etsy execution path.
 *
 * It never returns token material and cannot exchange, refresh, persist, or use
 * credentials. Existing connector OAuth remains a separate integration surface.
 */
final class EtsyTokenLifecycle
{
    private const REFRESH_SKEW_SECONDS=120;

    /** @return array<string,mixed>|WP_Error */
    public static function evaluate(array $metadata,int $now): array|WP_Error
    {
        if ($now < 1) return self::error('clock','A valid current timestamp is required.');

        $accessPresent=($metadata['access_token_present']??null)===true;
        $refreshPresent=($metadata['refresh_token_present']??null)===true;
        $expiresAt=(int)($metadata['access_expires_at']??0);

        if (!$accessPresent) {
            return self::decision('REAUTHORIZE_REQUIRED',false,false,$refreshPresent);
        }
        if ($expiresAt < 1) {
            return self::error('expiry','Access-token expiry metadata is required.');
        }

        if ($expiresAt <= ($now+self::REFRESH_SKEW_SECONDS)) {
            if (!$refreshPresent) return self::decision('REAUTHORIZE_REQUIRED',false,false,false);
            return self::decision('REFRESH_REQUIRED',false,true,true);
        }

        return self::decision('ACCESS_TOKEN_CURRENT',true,false,$refreshPresent);
    }

    /** @return array<string,mixed> */
    private static function decision(string $state,bool $accessUsable,bool $refreshEligible,bool $refreshPresent): array
    {
        return [
            'state'=>$state,
            'access_token_usable'=>$accessUsable,
            'refresh_token_present'=>$refreshPresent,
            'refresh_eligible'=>$refreshEligible,
            'token_material_exposed'=>false,
            'token_request_permitted'=>false,
            'adapter_invoked'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private static function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_token_lifecycle_'.$code,$message,['status'=>409]);
    }
}
