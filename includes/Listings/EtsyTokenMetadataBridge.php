<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use DigiForge\Integrations\Repository;
use WP_Error;

/**
 * Read-only bridge from connector public metadata to controlled token lifecycle.
 * It never decrypts or returns token material and never refreshes credentials.
 */
final class EtsyTokenMetadataBridge
{
    public function __construct(private Repository $integrations) {}

    /** @return array<string,mixed>|WP_Error */
    public function evaluate(int $integrationId,int $now): array|WP_Error
    {
        if ($integrationId<1) return $this->error('integration','A valid integration id is required.');

        $integration=$this->integrations->find($integrationId);
        if (!is_array($integration) || ($integration['provider']??'')!=='etsy') {
            return $this->error('integration','An Etsy integration is required.');
        }

        $names=[];
        foreach ((array)($integration['secrets']??[]) as $secret) {
            if (is_array($secret) && is_string($secret['secret_name']??null)) {
                $names[(string)$secret['secret_name']]=true;
            }
        }

        $oauth=is_array($integration['config']['etsy_oauth']??null)
            ? $integration['config']['etsy_oauth']
            : [];
        $expiresRaw=trim((string)($oauth['access_expires_at']??''));
        $expiresAt=$expiresRaw==='' ? 0 : strtotime($expiresRaw);
        if ($expiresRaw!=='' && ($expiresAt===false || $expiresAt<1)) {
            return $this->error('expiry','Stored Etsy access-token expiry metadata is invalid.');
        }

        $decision=EtsyTokenLifecycle::evaluate([
            'access_token_present'=>isset($names['access_token']),
            'refresh_token_present'=>isset($names['refresh_token']),
            'access_expires_at'=>(int)$expiresAt,
        ],$now);
        if ($decision instanceof WP_Error) return $decision;

        return [
            'state'=>'ETSY_TOKEN_METADATA_EVALUATED',
            'integration_id'=>$integrationId,
            'connection_key'=>(string)($integration['connection_key']??''),
            'token_lifecycle'=>$decision,
            'token_material_exposed'=>false,
            'token_refresh_performed'=>false,
            'token_request_permitted'=>false,
            'adapter_invoked'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_token_metadata_'.$code,$message,['status'=>409]);
    }
}
