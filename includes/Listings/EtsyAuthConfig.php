<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Pure fail-closed configuration contract for a future Etsy OAuth client.
 *
 * Secrets are validated in memory only and are never returned. This class
 * performs no HTTP, token refresh, persistence, adapter invocation, or external
 * action.
 */
final class EtsyAuthConfig
{
    /** @return array<string,mixed>|WP_Error */
    public static function validate(array $config): array|WP_Error
    {
        $clientId=trim((string)($config['client_id']??''));
        $clientSecret=trim((string)($config['client_secret']??''));
        $redirectUri=trim((string)($config['redirect_uri']??''));
        $scopes=$config['scopes']??null;

        if ($clientId==='' || strlen($clientId)>191 || $clientSecret==='' || strlen($clientSecret)>512) {
            return self::error('credentials','Etsy client credentials are required.');
        }
        if ($redirectUri==='' || strlen($redirectUri)>2048 || filter_var($redirectUri,FILTER_VALIDATE_URL)===false) {
            return self::error('redirect_uri','A valid Etsy OAuth redirect URI is required.');
        }
        $parts=parse_url($redirectUri);
        if (!is_array($parts) || strtolower((string)($parts['scheme']??''))!=='https' || empty($parts['host'])) {
            return self::error('redirect_uri','Etsy OAuth redirect URI must use HTTPS.');
        }
        if (!is_array($scopes) || $scopes===[]) {
            return self::error('scopes','Explicit Etsy OAuth scopes are required.');
        }

        $normalized=[];
        foreach ($scopes as $scope) {
            if (!is_string($scope)) return self::error('scopes','Etsy OAuth scopes must be strings.');
            $scope=trim($scope);
            if ($scope==='' || strlen($scope)>128 || !preg_match('/^[A-Za-z0-9:_-]+$/',$scope)) {
                return self::error('scopes','Etsy OAuth scope is invalid.');
            }
            $normalized[$scope]=true;
        }
        $normalized=array_keys($normalized);
        sort($normalized,SORT_STRING);

        return [
            'state'=>'ETSY_AUTH_CONFIG_VALIDATED',
            'client_id_present'=>true,
            'client_secret_present'=>true,
            'redirect_uri'=>$redirectUri,
            'scopes'=>$normalized,
            'credentials_exposed'=>false,
            'token_request_permitted'=>false,
            'adapter_invoked'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private static function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_auth_config_'.$code,$message,['status'=>409]);
    }
}
