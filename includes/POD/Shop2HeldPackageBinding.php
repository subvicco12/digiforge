<?php
declare(strict_types=1);
namespace DigiForge\POD;

use DigiForge\Connector\Printify\{CredentialReference,NativeAuthorityAdapter,NativeBusinessArtworkEvidence,NativeBusinessEvidence,NativePortContract,ShopBinding};
use WP_Error;

/** Current local evidence only. Never allocation, publication, credential or execution authority. */
final class Shop2HeldPackageBinding
{
    public const SCHEMA='DF_SHOP2_PROOF_BUSINESS_BINDING_1';

    /** Create a fresh immutable package association; never choose a proof here. */
    public static function prepare(array $link,string $research,string $factory,array $selectors,array $evidence,array $constraints=[]):array|WP_Error
    {
        $intake=self::intake($link,$factory);if(is_wp_error($intake))return $intake;
        $claims=self::claims($constraints,$intake);if(is_wp_error($claims))return $claims;
        if(($intake['versions']['research_hash']??null)!==$research)return self::error('proof_research');
        $native=$evidence['specification']['native_fields']??null;$fields=$intake['field_schema']['fields'];
        if(!is_array($native)||count($native)!==count($fields))return self::error('native_fields');
        foreach($fields as $field){$matches=array_values(array_filter($native,static fn($n)=>is_array($n)&&($n['layer']??null)===$field['key']));if(count($matches)!==1||($matches[0]['type']??null)!=='TEXT'||($matches[0]['required']??null)!==$field['required']||($matches[0]['max_characters']??null)!==$field['max_length'])return self::error('native_fields');}
        $questions=$evidence['native_fields']??null;if(!is_array($questions)||!array_is_list($questions)||count($questions)!==count($fields))return self::error('native_questions');
        foreach($fields as $i=>$field)if(($questions[$i]['question_type']??null)!=='text_input'||($questions[$i]['required']??null)!==$field['required']||($questions[$i]['max_allowed_characters']??null)!==$field['max_length'])return self::error('native_questions');
        $business=self::business($link,$research,$factory,$selectors,$evidence);if(is_wp_error($business))return $business;
        $again=self::intake($link,$factory);if(is_wp_error($again)||$again!==$intake)return self::error('proof_changed');
        $binding=['schema'=>self::SCHEMA,'shop_key'=>'personalized_pod','business'=>$business,'personalization_intake'=>$intake,'publication_authorized'=>false,'allocation_verified'=>false,'native_product_version'=>null,'provider_native_identity_verified'=>false,'supplier_certified'=>false,'native_typography_verified'=>false,'buyer_specific_production_approval_required'=>true,'external_execution_authorized'=>false,'release_hold'=>true];
        $binding['binding_hash']=NativePortContract::hash($binding);return $binding;
    }

    /** Reconstruct from protected storage; caller IDs/hashes are constraints, never authority. */
    public static function inspect(array $link,string $research,string $factory,string $package,array $constraints=[]):array|WP_Error
    {
        try{
            if(!Shop2ProductWorkflow::access(true))return self::error('access');
            $v=(new ProductPackageRepository())->read('personalized_pod',$package);if(is_wp_error($v))return $v;
            $binding=$v['record']['evidence']['shop2_binding']??null;
            if(!is_array($binding)||($binding['schema']??null)!==self::SCHEMA||!is_array($binding['business']['selectors']??null)||($v['record']['commercial_design_metadata']['design_hash']??null)!==$factory)return self::error('missing_binding');
            foreach(['business_integration_id'=>'integration_id','business_mapping_id'=>'provider_mapping_id','business_asset_spec_id'=>'asset_spec_id']as $postKey=>$key)if(array_key_exists($postKey,$constraints)&&((!is_int($constraints[$postKey])&&!is_string($constraints[$postKey]))||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$constraints[$postKey])||(string)$constraints[$postKey]!== (string)$binding['business']['selectors'][$key]))return self::error('substituted_business');
            $expected=$binding['personalization_intake']['intake_hash']??null;if(!is_string($expected)||preg_match('/^[a-f0-9]{64}$/D',$expected)!==1)return self::error('intake_identity');
            $verified=self::intake($link,$factory,$expected);if(is_wp_error($verified))return $verified;
            $current=self::prepare($link,$research,$factory,$binding['business']['selectors'],$v['record']['evidence'],$constraints);if(is_wp_error($current))return $current;
            if(\DigiForge\Listings\Validator::canonicalJson($current)!==\DigiForge\Listings\Validator::canonicalJson($binding))return self::error('stale_binding');
            return ['binding'=>$current,'package_record_hash'=>$package,'decision_hash'=>NativePortContract::hash(['decision'=>$v['decision']])];
        }catch(\Throwable){return self::error('unavailable');}
    }

    public static function export(array $link,string $research,string $factory,string $package,array $constraints=[]):string|WP_Error
    {
        try{
            $first=self::inspect($link,$research,$factory,$package,$constraints);if(is_wp_error($first))return $first;
            $workflow=new Shop2ProductWorkflow();$raw=$workflow->export($link,$package);if(is_wp_error($raw))return $raw;
            // The final expensive export cannot return authority captured before it.
            $last=self::inspect($link,$research,$factory,$package,$constraints);if(is_wp_error($last)||$last!==$first)return self::error('changed');
            $out=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$out['proof_business_binding']=$last['binding'];$out['immutable_package_record_sha256']=$package;
            $encoded=\DigiForge\Listings\Validator::canonicalJson($out);return strlen($encoded)<=196608?$encoded:self::error('size');
        }catch(\Throwable){return self::error('unavailable');}
    }

    private static function intake(array $link,string $factory,?string $expected=null):array|WP_Error
    {
        if(!class_exists(\DigiForge\Research\ArtworkPersonalizationIntake::class))return self::error('proof_interface_unavailable');
        try{
            $r=(new \DigiForge\Research\ArtworkPersonalizationIntake())->resolve('personalized_pod',$link['hash'],$factory,$expected);
            if(is_wp_error($r))return $r;
            if(!is_array($r)||($r['schema']??null)!==\DigiForge\Research\ArtworkPersonalizationIntake::SCHEMA||$r['schema']!=='DF_ARTWORK_PERSONALIZATION_INTAKE_1'||($r['shop_key']??null)!=='personalized_pod')return self::error('proof_interface');
            foreach(['external_execution_authorized','production_authorized','package_approval_issued','provider_certified']as $flag)if(($r[$flag]??null)!==false)return self::error('proof_authority');
            if(($r['release_hold']??null)!==true)return self::error('proof_authority');
            $body=$r;unset($body['intake_hash']);if(!is_string($r['intake_hash']??null)||!hash_equals($r['intake_hash'],hash('sha256',\DigiForge\Research\CommercialResearchContract::json($body))))return self::error('proof_digest');
            // Exact declared reference projection: do not duplicate private sample values/SVG or alter the upstream digest.
            $projection=['schema'=>'DF_SHOP2_VERIFIED_PROOF_REFERENCE_1','source_schema'=>$r['schema'],'intake_hash'=>$r['intake_hash'],'actor_scope'=>$r['actor_scope'],'selection'=>$r['selection'],'versions'=>$r['versions'],'field_schema'=>['hash'=>$r['field_schema']['hash'],'fields'=>$r['field_schema']['record']['data']['fields']],
                'proof'=>['hash'=>$r['proof']['hash'],'proof_sha256'=>$r['proof']['record']['data']['result']['proof_sha256'],'state'=>$r['proof']['record']['data']['result']['state']],
                'review'=>array_intersect_key($r['review'],array_flip(['hash','decision','actor','created_at','production_authorized'])),
                'factory'=>array_intersect_key($r['factory'],array_flip(['hash','review_hash','review_state'])),
                'source_provenance_sha256'=>hash('sha256',\DigiForge\Research\CommercialResearchContract::json($r['artwork']['provenance'])),
                'local_review_only'=>true,'provider_certified'=>false,'production_authorized'=>false,'package_approval_issued'=>false,'external_execution_authorized'=>false,'release_hold'=>true];
            \DigiForge\Listings\Validator::structured($projection);return $projection;
        }catch(\Throwable){return self::error('proof_unavailable');}
    }

    private static function claims(array $claims,array $intake):true|WP_Error
    {
        foreach(['proof','review','schema','intake','personalization_intake','shop2_binding','business_binding']as $key)if(array_key_exists($key,$claims))return self::error('unsolicited_metadata');
        foreach(['personalization_hash'=>'proof_hash','proof_hash'=>'proof_hash','schema_hash'=>'schema_hash','review_hash'=>'review_hash']as $key=>$version)if(array_key_exists($key,$claims)&&(!is_string($claims[$key])||!hash_equals($intake['versions'][$version],$claims[$key])))return self::error('substituted_proof');
        if(array_key_exists('intake_hash',$claims)&&(!is_string($claims['intake_hash'])||!hash_equals($intake['intake_hash'],$claims['intake_hash'])))return self::error('substituted_intake');
        return true;
    }

    /** IDs are untrusted selectors; all authority comes from current protected core repositories. */
    public static function business(array $link,string $research,string $factory,array $selectors,array $evidence):array|WP_Error
    {
        if(!Shop2ProductWorkflow::access(true))return self::error('access');
        try{
            $ids=[];
            foreach(['integration_id','provider_mapping_id','asset_spec_id']as $key){
                $v=$selectors[$key]??null;
                if((!is_int($v)&&!is_string($v))||preg_match('/^[1-9][0-9]{0,9}$/D',(string)$v)!==1||(int)$v>2147483647)return self::error('selectors');
                $ids[$key]=(int)$v;
            }
            $verified=(new ScopedProviderConnection())->verified('personalized_pod',$ids['integration_id'],'printify.connection.read');
            if(is_wp_error($verified))return $verified;
            $scope=new ShopBinding(2,'personalized_pod',$ids['integration_id'],$verified['external_shop_reference'],$verified['environment'],$verified['review_revision'],new CredentialReference($ids['integration_id']),$verified['binding_hash'],$verified['expires_at']);
            $actor=get_current_user_id();
            $business=NativeBusinessEvidence::product($scope,$actor,$ids['provider_mapping_id']);if(is_wp_error($business))return $business;
            $art=NativeBusinessArtworkEvidence::resolve($scope,$actor,$ids['provider_mapping_id'],$ids['asset_spec_id']);if(is_wp_error($art))return $art;
            if($art['supplier_workflow_hash']!==($link['hash']??null)||$art['design_factory_hash']!==$factory||$art['variant_ids']!==[$link['record']['variant_id']]||$art['blueprint_id']!==$link['record']['blueprint_id']||$art['provider_id']!==$link['record']['provider_id']||$art['product_version_id']!==$business['product_version_id']||$art['production_plan_id']!==$business['production_plan_id']||$art['business_evidence_hash']!==$business['evidence_hash'])return self::error('artwork_business');
            if($business['environment']!==($evidence['environment']??null)||$business['provider_product_key']!==($evidence['fixture']['printify_product_id']??null)||$business['provider_variant_key']!==(string)$link['record']['variant_id'])return self::error('provider_identity');
            $workflow=new Shop2ProductWorkflow();$current=$workflow->read($link['hash']);if(is_wp_error($current))return $current;
            if($current!==$link||is_wp_error($workflow->context($current,$research))||is_wp_error($workflow->package($current,$evidence)))return self::error('supplier_context');
            $handoff=(new \DigiForge\Research\DesignFactoryRepository())->handoff('personalized_pod',$factory);if(is_wp_error($handoff))return $handoff;
            if($handoff['design_metadata']['commercial_hash']!==$research||$handoff['artwork']['version_hash']!==$art['artwork_version_hash'])return self::error('research_artwork');
            // Stable business/material identity excludes the inspecting actor. Every access still resolves current actor/session.
            $projection=['selectors'=>$ids,'business_id'=>$business['business_id'],'store_id'=>$business['store_id'],'product_program_id'=>$business['product_program_id'],'product_id'=>$business['product_id'],'product_version_id'=>$business['product_version_id'],'production_plan_id'=>$business['production_plan_id'],'provider_product_key'=>$business['provider_product_key'],'provider_variant_key'=>$business['provider_variant_key'],'business_dependencies'=>$business['dependency_hashes'],'approval_events'=>['product_version'=>$business['product_approval_event_id'],'mapping'=>$business['mapping_approval_event_id'],'production_plan'=>$business['plan_approval_event_id'],'asset_spec'=>$art['spec_approval_event_id']],'artwork_dependency_hash'=>$art['dependency_hash'],'artwork_version_hash'=>$art['artwork_version_hash'],'design_factory_hash'=>$factory,'supplier_workflow_hash'=>$link['hash'],'research_hash'=>$research,'blueprint_id'=>$art['blueprint_id'],'provider_id'=>$art['provider_id'],'variant_ids'=>$art['variant_ids'],'connection_binding_hash'=>$scope->bindingHash,'authorization_revision'=>$scope->revision];
            $again=NativeBusinessEvidence::product($scope,$actor,$ids['provider_mapping_id'],$business['evidence_hash']);if(is_wp_error($again))return $again;
            $authority=NativeAuthorityAdapter::scope($scope,$actor,['manage_digiforge_products','manage_digiforge_pod','manage_digiforge_research','manage_digiforge_listings']);
            return is_wp_error($authority)?$authority:$projection;
        }catch(\Throwable){return self::error('unavailable');}
    }
    private static function error(string $reason):WP_Error{return new WP_Error('shop2_held_binding_'.$reason,'Current reviewed personalization and exact approved business binding required: '.$reason.'.',['status'=>409,'release_hold'=>true,'external_execution_authorized'=>false,'retry_permitted'=>false]);}
}
