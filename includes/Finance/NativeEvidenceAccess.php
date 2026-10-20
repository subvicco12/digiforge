<?php
declare(strict_types=1);
namespace DigiForge\Finance;

use DigiForge\Database\NativeFinanceEvidenceSchema;
use DigiForge\Security\ShopAccess;
use DigiForge\Security\ShopAccessPolicy;
use WP_Error;

/** Current local custody restriction, never authentic provider/account authority. */
final class NativeEvidenceAccess
{
    public static function installation():string
    {
        return Validator::hash(['site'=>rtrim(site_url(),'/'),'blog'=>get_current_blog_id(),'environment'=>wp_get_environment_type(),'main_site'=>defined('DIGIFORGE_MAIN_SITE_URL')?DIGIFORGE_MAIN_SITE_URL:'']);
    }
    public static function validate(array $scope):array|WP_Error
    {
        $fields=['shop_key','environment','business_id','store_id','external_shop_id','external_account_id','integration_id','source_contract_sha256','installation_sha256','binding_hash','binding_revision'];
        $keys=array_keys($scope);sort($keys);sort($fields);
        if($keys!==$fields||!NativeFinanceEvidenceSchema::offline()||($scope['environment']??null)!=='test'||($scope['shop_key']??null)!=='digital'
            ||($scope['source_contract_sha256']??null)!==EtsyFinancialSourceParser::CONTRACT_SHA256||($scope['installation_sha256']??null)!==self::installation())return self::error();
        foreach(['business_id','store_id','external_shop_id','external_account_id','integration_id','binding_revision']as$key)if(!is_int($scope[$key])||$scope[$key]<1)return self::error();
        if(!is_string($scope['binding_hash'])||preg_match('/^[a-f0-9]{64}$/D',$scope['binding_hash'])!==1||get_current_user_id()<1||!current_user_can('manage_digiforge'))return self::error();
        foreach(ShopAccessPolicy::canonicalShops()as$shop)if(ShopAccess::authorize($shop,'manage_digiforge_finance') instanceof WP_Error)return self::error();
        $binding=(new DigitalSourceBinding())->current($scope['business_id'],$scope['store_id'],'test');
        if($binding instanceof WP_Error||$binding['integration_id']!==$scope['integration_id']||$binding['external_account_reference']!==(string)$scope['external_account_id']
            ||$binding['external_shop_reference']!==(string)$scope['external_shop_id']||$binding['ownership_hash']!==$scope['binding_hash']||$binding['ownership_revision']!==$scope['binding_revision'])return self::error();
        return $binding;
    }
    /** Authoritative-account operations are deliberately unsupported by local reviews. */
    public static function authoritative(array $scope):WP_Error{return self::error();}
    public static function error():WP_Error{return new WP_Error('finance_native_evidence_blocked','Current authorized offline native evidence is unavailable.',['status'=>409,'authenticity'=>'UNKNOWN','accounting_import_authorized'=>false,'external_execution_authorized'=>false]);}
}
