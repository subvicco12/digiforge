<?php

declare(strict_types=1);

namespace DigiForge\POD;

use DigiForge\Database\Tables;
use DigiForge\Core\Settings;
use DigiForge\Integrations\CredentialVault;
use DigiForge\Integrations\Repository as IntegrationRepository;
use WP_Error;

/** Explicit, guarded, read-only Printify catalog synchronization boundary. */
final class PrintifyCatalogSyncService
{
    /** @var callable|null */ private $clientFactory;
    public function __construct(?callable $clientFactory=null){$this->clientFactory=$clientFactory;}

    public function syncBlueprint(int $integrationId,int $blueprintId): array|WP_Error
    {
        if (! Settings::is_enabled('printify')) return new WP_Error('digiforge_printify_locked','Scoped Printify authorization is required before catalog network access.',['status'=>409]);
        $integration=(new IntegrationRepository())->find($integrationId);if(!$integration||($integration['provider']??'')!=='printify')return new WP_Error('digiforge_printify_integration','A Printify integration is required.',['status'=>409]);if(($integration['status']??'')!=='CONFIGURED'||empty($integration['enabled']))return new WP_Error('digiforge_printify_disabled','Printify must be CONFIGURED and explicitly enabled before catalog sync.',['status'=>409]);if($blueprintId<1)return new WP_Error('digiforge_printify_blueprint','A positive blueprint ID is required.',['status'=>400]);$token=$this->credential($integrationId);if(is_wp_error($token))return $token;$client=$this->clientFactory?($this->clientFactory)():new PrintifyCatalogClient();if(!$client instanceof PrintifyCatalogClient){unset($token);return new WP_Error('digiforge_printify_client','Printify catalog client is unavailable.',['status'=>500]);}$blueprint=$client->blueprint($token,$blueprintId);if(is_wp_error($blueprint)){unset($token);return $blueprint;}$providers=$client->providers($token,$blueprintId);unset($token);if(is_wp_error($providers))return $providers;return ['provider'=>'printify','environment'=>(string)$integration['environment'],'blueprint'=>$blueprint,'providers'=>$providers,'observed_at'=>gmdate('c')];
    }

    /** Fetch provider variants and persist only normalized shared catalog evidence. */
    public function syncProviderVariants(int $integrationId,int $blueprintId,int $providerId): array|WP_Error
    {
        if (! Settings::is_enabled('printify')) return new WP_Error('digiforge_printify_locked','Scoped Printify authorization is required before catalog network access.',['status'=>409]);
        $integration=(new IntegrationRepository())->find($integrationId);if(!$integration||($integration['provider']??'')!=='printify'||($integration['status']??'')!=='CONFIGURED'||empty($integration['enabled']))return new WP_Error('digiforge_printify_disabled','An enabled CONFIGURED Printify integration is required.',['status'=>409]);if($blueprintId<1||$providerId<1)return new WP_Error('digiforge_printify_identity','Positive blueprint and provider IDs are required.',['status'=>400]);$token=$this->credential($integrationId);if(is_wp_error($token))return $token;$client=$this->clientFactory?($this->clientFactory)():new PrintifyCatalogClient();if(!$client instanceof PrintifyCatalogClient){unset($token);return new WP_Error('digiforge_printify_client','Printify catalog client is unavailable.',['status'=>500]);}$response=$client->variants($token,$blueprintId,$providerId);if(is_wp_error($response)){unset($token);return $response;}$shipping=$client->shipping($token,$blueprintId,$providerId);unset($token);if(is_wp_error($shipping))return $shipping;$variants=$response['variants']??$response;if(!is_array($variants))return new WP_Error('digiforge_printify_shape','Printify variant response is unsupported.',['status'=>502]);$shippingProfile=$this->shippingEvidence($shipping);
        $persistence=new PrintifyCatalogPersistence();$stored=[];$observed=gmdate('c');foreach($variants as $variant){if(!is_array($variant))continue;$id=absint($variant['id']??0);if($id<1)continue;$cost=$this->moneyEvidence($variant['cost']??0);if(is_wp_error($cost))return $cost;$raw=['blueprint_id'=>$blueprintId,'print_provider_id'=>$providerId,'variant_id'=>$id,'environment'=>(string)$integration['environment'],'title'=>(string)($response['title']??''),'variant_label'=>(string)($variant['title']??''),'attributes'=>$variant,'currency'=>(string)($variant['currency']??'USD'),'base_cost'=>$cost,'shipping_profile'=>$shippingProfile,'availability_state'=>$this->availabilityEvidence($variant),'source_revision'=>(string)($variant['updated_at']??''),'observed_at'=>$observed];$saved=$persistence->persistVariant($raw);if(is_wp_error($saved))return $saved;$stored[]=$saved;}return ['provider'=>'printify','blueprint_id'=>$blueprintId,'print_provider_id'=>$providerId,'variant_count'=>count($stored),'variants'=>$stored,'observed_at'=>$observed];
    }

    /** Printify catalog prices are commonly represented in integer minor units; reject ambiguous shapes. */
    private function moneyEvidence(mixed $value): float|WP_Error
    {
        if(is_int($value)&&$value>=0)return round($value/100,4);if(is_float($value)&&is_finite($value)&&$value>=0)return round($value,4);if(is_string($value)&&preg_match('/^\d+$/',$value)===1)return round(((int)$value)/100,4);if(is_string($value)&&is_numeric($value)){ $number=(float)$value;if(is_finite($number)&&$number>=0)return round($number,4); }return new WP_Error('digiforge_printify_cost','Printify variant cost evidence is invalid.',['status'=>502]);
    }

    /** Preserve provider shipping evidence as deterministic read-only catalog metadata. */
    private function shippingEvidence(array $shipping): array
    {
        $normalize=function(mixed $value) use (&$normalize): mixed {if(!is_array($value))return $value;$out=[];foreach($value as $key=>$item)$out[(string)$key]=$normalize($item);if(!array_is_list($out))ksort($out,SORT_STRING);return $out;};return $normalize($shipping);
    }

    private function availabilityEvidence(array $variant): string
    {
        if(array_key_exists('is_available',$variant))return $variant['is_available']===true?'AVAILABLE':($variant['is_available']===false?'UNAVAILABLE':'UNKNOWN');if(array_key_exists('is_enabled',$variant))return $variant['is_enabled']===true?'AVAILABLE':($variant['is_enabled']===false?'UNAVAILABLE':'UNKNOWN');return 'UNKNOWN';
    }

    private function credential(int $integrationId): string|WP_Error
    {
        global $wpdb;foreach(['personal_access_token','access_token'] as $name){$cipher=$wpdb->get_var($wpdb->prepare('SELECT ciphertext FROM '.Tables::integration_secrets().' WHERE integration_id = %d AND secret_name = %s LIMIT 1',$integrationId,$name));if(!is_string($cipher)||$cipher==='')continue;try{return CredentialVault::decrypt($cipher,IntegrationRepository::secretContext($integrationId,$name));}catch(\Throwable){return new WP_Error('digiforge_printify_credential','Stored Printify credential could not be decrypted.',['status'=>500]);}}return new WP_Error('digiforge_printify_credential','Store a Printify credential before catalog sync.',['status'=>409]);
    }
}
