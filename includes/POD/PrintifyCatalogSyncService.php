<?php

declare(strict_types=1);

namespace DigiForge\POD;

use DigiForge\Database\Tables;
use DigiForge\Integrations\CredentialVault;
use DigiForge\Integrations\Repository as IntegrationRepository;
use WP_Error;

/**
 * Explicit read-only Printify catalog orchestration boundary.
 * Provider reads are allowed only for an enabled CONFIGURED Printify connector.
 * This service never publishes products, creates orders, or mutates templates.
 */
final class PrintifyCatalogSyncService
{
    public function __construct(private ?\Closure $clientFactory=null) {}

    public function syncBlueprint(int $integrationId,int $blueprintId): array|WP_Error
    {
        $integration=(new IntegrationRepository())->find($integrationId);
        if(!$integration || ($integration['provider']??'')!=='printify') return new WP_Error('digiforge_printify_integration','A Printify integration is required.',['status'=>409]);
        if(($integration['status']??'')!=='CONFIGURED' || empty($integration['enabled'])) return new WP_Error('digiforge_printify_disabled','Printify must be CONFIGURED and explicitly enabled before catalog sync.',['status'=>409]);
        if($blueprintId<1) return new WP_Error('digiforge_printify_blueprint','A positive blueprint ID is required.',['status'=>400]);

        $token=$this->credential($integrationId);
        if(is_wp_error($token)) return $token;
        $factory=$this->clientFactory;
        $client=$factory ? $factory($token) : new PrintifyCatalogClient($token);
        unset($token);
        if(!$client instanceof PrintifyCatalogClient) return new WP_Error('digiforge_printify_client','Printify catalog client is unavailable.',['status'=>500]);

        $blueprint=$client->blueprint($blueprintId); if(is_wp_error($blueprint)) return $blueprint;
        $providers=$client->providers($blueprintId); if(is_wp_error($providers)) return $providers;
        return ['provider'=>'printify','environment'=>(string)$integration['environment'],'blueprint'=>$blueprint,'providers'=>$providers,'observed_at'=>current_time('mysql',true)];
    }

    private function credential(int $integrationId): string|WP_Error
    {
        global $wpdb;
        foreach(['personal_access_token','access_token'] as $name){
            $cipher=$wpdb->get_var($wpdb->prepare('SELECT ciphertext FROM '.Tables::integration_secrets().' WHERE integration_id = %d AND secret_name = %s LIMIT 1',$integrationId,$name));
            if(!is_string($cipher)||$cipher==='') continue;
            try { return CredentialVault::decrypt($cipher,IntegrationRepository::secretContext($integrationId,$name)); }
            catch(\Throwable){ return new WP_Error('digiforge_printify_credential','Stored Printify credential could not be decrypted.',['status'=>500]); }
        }
        return new WP_Error('digiforge_printify_credential','Store a Printify credential before catalog sync.',['status'=>409]);
    }
}
