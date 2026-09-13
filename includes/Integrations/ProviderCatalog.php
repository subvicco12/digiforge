<?php

declare(strict_types=1);

namespace DigiForge\Integrations;

/**
 * Metadata-only provider catalog used by the Integration Control Center.
 * No provider network requests are performed here.
 */
final class ProviderCatalog
{
    /**
     * @return array<string, array{
     *   label:string,
     *   description:string,
     *   auth:string,
     *   suggested_secrets:array<string,string>,
     *   config_fields:array<string,string>
     * }>
     */
    public static function providers(): array
    {
        return [
            'etsy' => [
                'label' => 'Etsy',
                'description' => 'Etsy Open API v3 seller integration.',
                'auth' => 'OAuth 2.0 + app API key',
                'suggested_secrets' => [
                    'keystring' => 'App API keystring',
                    'shared_secret' => 'App shared secret',
                    'access_token' => 'OAuth access token',
                    'refresh_token' => 'OAuth refresh token',
                ],
                'config_fields' => [
                    'shop_id' => 'Etsy shop ID',
                    'redirect_uri' => 'OAuth redirect URI',
                    'scopes' => 'OAuth scopes',
                ],
            ],
            'printify' => [
                'label' => 'Printify',
                'description' => 'Printify merchant/POD integration.',
                'auth' => 'Personal Access Token or OAuth 2.0',
                'suggested_secrets' => [
                    'personal_access_token' => 'Personal Access Token',
                    'access_token' => 'OAuth access token',
                    'refresh_token' => 'OAuth refresh token',
                ],
                'config_fields' => [
                    'shop_id' => 'Printify shop ID',
                    'api_version' => 'Preferred API version',
                ],
            ],
            'gelato' => [
                'label' => 'Gelato',
                'description' => 'Gelato POD integration.',
                'auth' => 'API credential',
                'suggested_secrets' => [
                    'api_key' => 'API key',
                ],
                'config_fields' => [
                    'store_id' => 'Store/account identifier',
                    'api_base' => 'API base URL override',
                ],
            ],
            'ai' => [
                'label' => 'AI Provider',
                'description' => 'External AI provider connection.',
                'auth' => 'Provider API credential',
                'suggested_secrets' => [
                    'api_key' => 'API key',
                ],
                'config_fields' => [
                    'vendor' => 'Provider/vendor name',
                    'model' => 'Default model',
                    'api_base' => 'API base URL override',
                ],
            ],
        ];
    }

    public static function known(string $provider): bool
    {
        return isset(self::providers()[$provider]);
    }

    /** @return array<string,string> */
    public static function suggestedSecrets(string $provider): array
    {
        return self::providers()[$provider]['suggested_secrets'] ?? [];
    }

    /** @return array<string,string> */
    public static function configFields(string $provider): array
    {
        return self::providers()[$provider]['config_fields'] ?? [];
    }

    public static function label(string $provider): string
    {
        return self::providers()[$provider]['label'] ?? ucwords(str_replace(['-', '_'], ' ', $provider));
    }

    public static function validProviderSlug(string $provider): bool
    {
        return preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $provider) === 1;
    }
}
