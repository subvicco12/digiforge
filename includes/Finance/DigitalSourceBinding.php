<?php
declare(strict_types=1);
namespace DigiForge\Finance;

use DigiForge\Database\Tables;
use DigiForge\Database\QueryEvidence;
use DigiForge\Integrations\CredentialEvidenceVersion;
use DigiForge\Integrations\Repository as Integrations;
use DigiForge\Listings\EtsyVerifiedShopIdentity;
use DigiForge\Listings\Validator as Canonical;
use DigiForge\Security\ReviewedLocalEvidence;
use DigiForge\Security\ShopAccess;
use WP_Error;

/** One reviewed Digital/Etsy financial relation per environment; never an execution grant. */
final class DigitalSourceBinding
{
    private const FIELDS=['version','business_id','store_id','integration_id','environment','source_sha256','evidence_reference','observed_at'];
    private const SCHEMA='digiforge-finance-digital-source-binding-v1';

    /** Exact typed local evidence, with no credential values or provider payload interpretation. */
    public static function normalizeInput(array $input):array
    {
        $keys=array_keys($input);$expected=self::FIELDS;sort($keys);sort($expected);
        if($keys!==$expected||$input['version']!==1)throw new \InvalidArgumentException('Exact Digital binding v1 fields required.');
        foreach(['business_id','store_id','integration_id']as$field)if(!is_int($input[$field])||$input[$field]<1)throw new \InvalidArgumentException('Explicit positive integer identity required.');
        if(!is_string($input['environment'])||!in_array($input['environment'],Integrations::ENVIRONMENTS,true)
            ||!is_string($input['source_sha256'])||preg_match('/^[a-f0-9]{64}$/D',$input['source_sha256'])!==1
            ||!is_string($input['evidence_reference'])||preg_match('#^local://[A-Za-z0-9][A-Za-z0-9/_.:-]{0,230}$#D',$input['evidence_reference'])!==1)throw new \InvalidArgumentException('Explicit environment and local source evidence required.');
        foreach(explode('/',substr($input['evidence_reference'],8))as$part)if($part==='.'||$part==='..')throw new \InvalidArgumentException('Local source traversal is invalid.');
        DeclarationContract::timestamp($input['observed_at']);
        return $input;
    }

    public function propose(array $input,int $effectiveAt,int $expiresAt,string $requestId,int $revision):array|WP_Error
    {
        $access=ShopAccess::authorize('digital','manage_digiforge_connections');if($access instanceof WP_Error)return $access;
        try{$input=self::normalizeInput($input);}catch(\InvalidArgumentException){return self::error('invalid_input',400);}
        $observed=strtotime($input['observed_at']);
        if($observed===false||$observed>time()||$effectiveAt<$observed||$expiresAt<=$effectiveAt)return self::error('invalid_window',400);
        $payload=$this->snapshot($input);if($payload instanceof WP_Error)return $payload;
        return ReviewedLocalEvidence::propose('ownership','digital',self::key($input['environment']),$payload,$effectiveAt,$expiresAt,$requestId,$revision);
    }

    public function decide(int $business,int $store,string $environment,int $revision,string $hash,string $decision,string $requestId):array|WP_Error
    {
        $access=ShopAccess::authorize('digital','manage_digiforge_settings');if($access instanceof WP_Error)return $access;
        if(!self::scope($business,$store,$environment))return self::error('scope');
        $state=ReviewedLocalEvidence::read('ownership','digital',self::key($environment));if($state instanceof WP_Error)return $state;
        if(!is_array($state)||($state['payload']['business_id']??null)!==$business||($state['payload']['store_id']??null)!==$store||($state['payload']['environment']??null)!==$environment)return self::error('missing');
        if($decision==='APPROVED'){
            $current=$this->validatedSnapshot($state['payload'],$state['effective_at']);if($current instanceof WP_Error)return $current;
            if($state!==ReviewedLocalEvidence::read('ownership','digital',self::key($environment)))return self::error('changed');
        }
        return ReviewedLocalEvidence::decide('ownership','digital',self::key($environment),$revision,$hash,$decision,$requestId);
    }

    /** Current local assertion only; real provider ownership remains unverified. */
    public function current(int $business,int $store,string $environment):array|WP_Error
    {
        if(!self::scope($business,$store,$environment))return self::error('scope');
        $key=self::key($environment);$state=ReviewedLocalEvidence::approved('ownership','digital',$key);if($state instanceof WP_Error)return $state;
        $payload=$this->validatedSnapshot($state['payload'],$state['effective_at']);if($payload instanceof WP_Error)return $payload;
        if($payload['business_id']!==$business||$payload['store_id']!==$store||$payload['environment']!==$environment)return self::error('scope');
        if($state!==ReviewedLocalEvidence::approved('ownership','digital',$key))return self::error('changed');
        return ['ownership_id'=>0,'ownership_hash'=>$state['proposal_hash'],'ownership_revision'=>$state['revision'],
            'business_id'=>$business,'store_id'=>$store,'environment'=>$environment,'integration_id'=>$payload['integration_id'],
            'external_account_reference'=>$payload['external_account_reference'],'external_shop_reference'=>$payload['external_shop_reference'],
            'provider_identity_verified'=>false,'external_execution_authorized'=>false];
    }

    private function validatedSnapshot(array $payload,int $effectiveAt):array|WP_Error
    {
        try{$input=self::normalizeInput(array_intersect_key($payload,array_flip(self::FIELDS)));}catch(\InvalidArgumentException){return self::error('invalid_payload');}
        $observed=strtotime($input['observed_at']);
        if($observed===false||$observed>time()||$observed>$effectiveAt)return self::error('invalid_window');
        $current=$this->snapshot($input);if($current instanceof WP_Error)return $current;
        return Canonical::canonicalJson($payload)===Canonical::canonicalJson($current)?$current:self::error('drifted');
    }

    /** Reads non-secret registry evidence only; never decrypts or tests a connection. */
    private function snapshot(array $input):array|WP_Error
    {
        $integration=(new Integrations())->find($input['integration_id']);if($integration instanceof WP_Error)return $integration;
        if(!is_array($integration)||$integration['provider']!=='etsy'||$integration['status']!=='CONFIGURED'||$integration['environment']!==$input['environment'])return self::error('integration');
        $details=$integration['config']['_connection_test']['details']??null;
        if(!is_array($details))return self::error('identity');
        $account=self::positiveId($details['user_id']??null);$shop=self::positiveId($details['shop_id']??null);
        if($account===null||$shop===null)return self::error('identity');
        $identity=EtsyVerifiedShopIdentity::resolveAny('digital',(int)$shop,$input['environment']);if($identity instanceof WP_Error)return $identity;
        if($identity['integration_id']!==$input['integration_id'])return self::error('integration');
        $material=CredentialEvidenceVersion::fromMetadata($integration['secrets']);if($material===null)return self::error('material');
        global $wpdb;$registry=[];
        foreach([[Tables::businesses(),$input['business_id']],[Tables::stores(),$input['store_id']]]as[$table,$id]){
            $wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE id=%d LIMIT 1',$id),ARRAY_A);
            if(QueryEvidence::failed())return self::error('unavailable',503);
            if(!is_array($row)||$row['status']!=='ACTIVE')return self::error('registry');$registry[]=$row;
        }
        if((int)$registry[1]['business_id']!==$input['business_id']||$registry[1]['channel']!=='etsy')return self::error('registry');
        $wpdb->last_error='';$history=$wpdb->get_results($wpdb->prepare('SELECT id,event_type,actor_id,context FROM '.Tables::audit_log().' WHERE object_type=%s AND object_id=%s ORDER BY id ASC LIMIT 1001','integration',(string)$input['integration_id']),ARRAY_A);
        if(QueryEvidence::failed()||!is_array($history)||count($history)<1||count($history)>1000)return self::error('history_unavailable',503);
        return $input+['schema'=>self::SCHEMA,'shop_key'=>'digital','provider'=>'etsy',
            'external_account_reference'=>$account,'external_shop_reference'=>$shop,'connection_material_version'=>$material,
            'installation_sha256'=>self::hash(['site'=>rtrim(site_url(),'/'),'blog'=>get_current_blog_id(),'environment'=>wp_get_environment_type(),'main_site'=>defined('DIGIFORGE_MAIN_SITE_URL')?DIGIFORGE_MAIN_SITE_URL:'']),
            'integration_snapshot_sha256'=>self::hash($integration),'integration_history_sha256'=>self::hash($history),'registry_snapshot_sha256'=>self::hash($registry),
            'provider_identity_verified'=>false,'external_execution_authorized'=>false];
    }

    private static function positiveId(mixed $value):?string
    {
        return (is_string($value)||is_int($value))&&preg_match('/^[1-9][0-9]*$/D',(string)$value)===1
            &&filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])!==false?(string)$value:null;
    }
    private static function scope(int $business,int $store,string $environment):bool{return $business>0&&$store>0&&in_array($environment,Integrations::ENVIRONMENTS,true);}
    private static function hash(array $input):string{return hash('sha256',Canonical::canonicalJson($input));}
    private static function key(string $environment):string{return self::hash(['DF-OWN-001','finance-source-binding-v1','digital','etsy',$environment]);}
    private static function error(string $code,int $status=409):WP_Error{return new WP_Error('finance_digital_binding_'.$code,'Current exact independently reviewed Digital financial source evidence is required.',['status'=>$status,'external_execution_authorized'=>false]);}
}
