<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use DigiForge\Database\Tables;
use WP_Error;

final class EtsyVerifiedShopIdentity
{
    public static function resolve(int $integrationId,string $listingShopReference,int $shopId,?string $environment=null): array|WP_Error
    {
        if($integrationId<1||$shopId<1||trim($listingShopReference)==='') return self::error('identity','Integration, listing shop reference and numeric Etsy shop id are required.');
        global $wpdb;
        $wpdb->last_error='';
        $row=$wpdb->get_row($wpdb->prepare('SELECT provider,status,config,environment FROM '.Tables::integrations().' WHERE id=%d LIMIT 1',$integrationId),ARRAY_A);
        if(!empty($wpdb->last_error)) return self::error('evidence_unavailable','Configured Etsy integration evidence could not be read.',503);
        if(!is_array($row)||($row['provider']??'')!=='etsy'||($row['status']??'')!=='CONFIGURED') return self::error('integration','A configured Etsy integration is required.');
        if($environment!==null&&(!in_array($environment,\DigiForge\Integrations\Repository::ENVIRONMENTS,true)||($row['environment']??null)!==$environment))
            return self::error('environment','Configured Etsy identity does not match the exact listing environment.');
        $config=json_decode((string)($row['config']??'{}'),true);
        $test=is_array($config['_connection_test']??null)?$config['_connection_test']:[];
        $details=is_array($test['details']??null)?$test['details']:[];
        $rawId=$details['shop_id']??null;
        $rawName=$details['shop_name']??null;
        if((!is_int($rawId)&&!is_string($rawId))||!is_string($rawName)
            ||preg_match('/^[1-9][0-9]*$/D',(string)$rawId)!==1
            ||filter_var($rawId,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false)
            return self::error('unverified','Stored Etsy identity must contain an exact positive integer id and a textual name.');
        $verifiedId=(int)$rawId;
        $verifiedName=trim($rawName);
        if(($test['ok']??false)!==true||$verifiedId<1||$verifiedName==='') return self::error('unverified','A successful Etsy identity preflight with shop identity is required.');
        if($verifiedId!==$shopId) return self::error('scope','Verified Etsy shop identity does not match the requested numeric shop scope.');
        if(!self::referenceMatches($listingShopReference,$verifiedName,$verifiedId)) return self::error('scope','Verified Etsy shop identity does not match the approved listing shop scope.');
        $metadata=(new \DigiForge\Integrations\Repository())->secretMetadata($integrationId);
        if($metadata instanceof WP_Error)return self::error('evidence_unavailable','Current Etsy credential metadata could not be read.',503);
        $currentVersion=\DigiForge\Integrations\CredentialEvidenceVersion::fromMetadata($metadata);
        $observedVersion=$test['credential_evidence_version']??null;
        if($currentVersion===null||!is_string($observedVersion)||!hash_equals($currentVersion,$observedVersion))
            return self::error('credential_stale','Etsy identity preflight must match the current named credential evidence.');
        return ['integration_id'=>$integrationId,'shop_id'=>$verifiedId,'shop_name'=>$verifiedName];
    }
    public static function resolveAny(string $listingShopReference,int $shopId,string $environment): array|WP_Error
    {
        if(!in_array($environment,\DigiForge\Integrations\Repository::ENVIRONMENTS,true))
            return self::error('environment','An exact supported listing environment is required.');
        global $wpdb;
        $wpdb->last_error='';
        $ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM ".Tables::integrations()." WHERE BINARY provider=BINARY 'etsy' AND BINARY status=BINARY 'CONFIGURED' AND BINARY environment=BINARY %s ORDER BY id ASC",$environment));
        if(!is_array($ids)||!empty($wpdb->last_error)) return self::error('evidence_unavailable','Configured Etsy integration evidence could not be enumerated.',503);
        $match=null;$claims=0;
        foreach($ids as $id){
            // Count seller-id claims before checking friendly names. Conflicting
            // names must not hide two configured connectors for the same seller.
            $resolved=self::resolve((int)$id,(string)$shopId,$shopId,$environment);
            if($resolved instanceof WP_Error){
                if(($resolved->get_error_data()['status']??409)===503)return $resolved;
                if($resolved->get_error_code()==='digiforge_etsy_verified_shop_credential_stale')return $resolved;
                continue;
            }
            if(++$claims>1)return self::error('ambiguous','Multiple configured Etsy integrations claim this listing shop identity.');
            if(self::referenceMatches($listingShopReference,$resolved['shop_name'],$resolved['shop_id']))$match=$resolved;
        }
        return $match??self::error('scope','No verified Etsy integration matches the approved listing shop scope.');
    }

    private static function referenceMatches(string $approvedReference,string $verifiedName,int $verifiedId): bool
    {
        $approvedReference=trim($approvedReference);
        if(hash_equals($approvedReference,$verifiedName)) return true;
        if(ctype_digit($approvedReference)) return (int)$approvedReference===$verifiedId;
        // Historical pre-canonical aliases are accepted only for their exact verified production shop.
        $legacy=[
            'DigiCraftifyDigital'=>['shop_id'=>67757764,'shop_name'=>'KinetiqMatrixDesigns'],
            'DigiCraftifyGoods'=>['shop_id'=>68031896,'shop_name'=>'DigicraftifyShop'],
        ];
        $approvedReference=['digital'=>'DigiCraftifyDigital','personalized_pod'=>'DigiCraftifyGoods'][$approvedReference]??$approvedReference;
        $binding=$legacy[$approvedReference]??null;
        return is_array($binding)
            && (int)$binding['shop_id']===$verifiedId
            && hash_equals((string)$binding['shop_name'],$verifiedName);
    }

    private static function error(string $code,string $message,int $status=409): WP_Error
    {
        return new WP_Error('digiforge_etsy_verified_shop_'.$code,$message,['status'=>$status]);
    }
}
