<?php
declare(strict_types=1);
namespace DigiForge\Integrations;
use WP_Error;
/** Narrow read-only Etsy seller-taxonomy lookup using the integration's existing OAuth/app credentials. */
final class EtsySellerTaxonomyClient
{
    private const URL='https://api.etsy.com/v3/application/seller-taxonomy/nodes';
    public function fetchVerified(int $integrationId,int $taxonomyId): array|WP_Error
    {
        if($integrationId<1||$taxonomyId<1)return new WP_Error('digiforge_etsy_taxonomy_input','Valid integration and taxonomy identifiers are required.',['status'=>400]);
        $tester=new ConnectionTester();
        $identity=$tester->test($integrationId);
        if(is_wp_error($identity)||($identity['ok']??false)!==true||($identity['partial']??false)===true)return new WP_Error('digiforge_etsy_taxonomy_identity','Verified Etsy integration identity is required before taxonomy lookup.',['status'=>409]);
        $material=EtsyReadOnlyCredentialEnvelope::forIntegration($integrationId);
        if($material instanceof WP_Error)return $material;
        return $material->consume(function(string $apiKey,string $access) use($taxonomyId){
            $response=wp_remote_get(self::URL,['timeout'=>12,'redirection'=>0,'reject_unsafe_urls'=>true,'sslverify'=>true,'headers'=>['x-api-key'=>$apiKey,'Authorization'=>'Bearer '.$access,'Accept'=>'application/json','User-Agent'=>'DigiForge Etsy Taxonomy Reader']]);
            if(is_wp_error($response))return $response;
            if((int)wp_remote_retrieve_response_code($response)!==200)return new WP_Error('digiforge_etsy_taxonomy_http','Etsy seller taxonomy lookup did not return HTTP 200.',['status'=>409]);
            $body=(string)wp_remote_retrieve_body($response);
            if(strlen($body)>4194304)return new WP_Error('digiforge_etsy_taxonomy_size','Etsy seller taxonomy response exceeds the bounded reader limit.',['status'=>409]);
            $decoded=json_decode($body,true);
            if(!is_array($decoded))return new WP_Error('digiforge_etsy_taxonomy_json','Etsy seller taxonomy response must be valid JSON.',['status'=>409]);
            return \DigiForge\Listings\EtsySellerTaxonomyVerifier::verify($decoded,$taxonomyId);
        });
    }
}
