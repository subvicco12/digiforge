<?php
declare(strict_types=1);
namespace DigiForge\Finance;

use DigiForge\Database\Tables;
use DigiForge\Database\QueryEvidence as DatabaseEvidence;
use DigiForge\Portal\ProductEvidenceScope;
use DigiForge\POD\BusinessScopeRepository;
use WP_Error;

/** Reuses existing ownership and source identities; declarations never establish ownership. */
final class DeclarationReferences
{
    public function validate(array $declaration): array|WP_Error
    {
        $references=$declaration['scope']['shop_key']==='digital'
            ?(new DigitalDeclarationReferences())->validate($declaration):$this->pod($declaration);
        if($references instanceof WP_Error)return $references;
        $fx=$this->fx($declaration);if($fx instanceof WP_Error)return $fx;
        // FX lookup is another storage boundary: do not return a binding revoked or
        // reassigned while that evidence was being checked. This is a local snapshot,
        // never an external-operation or financial-approval authorization.
        $current=$declaration['scope']['shop_key']==='digital'
            ?(new DigitalDeclarationReferences())->validate($declaration):$this->pod($declaration);
        if($current instanceof WP_Error)return $current;
        if($current!==$references)return self::error('scope_changed','Finance ownership changed during source validation.');
        return $references+['fx_snapshot_hash'=>$fx];
    }

    private function pod(array $declaration): array|WP_Error
    {
        $scope=$declaration['scope']; $environment=$declaration['environment'];
        if($scope['shop_key']!=='personalized_pod')return self::error('scope_unsupported','Deferred shops have no supported financial ownership contract.');
        $business=$this->row(Tables::businesses(),$scope['business_id']); if($business instanceof WP_Error)return $business;
        $store=$this->row(Tables::stores(),$scope['store_id']); if($store instanceof WP_Error)return $store;
        if((int)$store['business_id']!==$scope['business_id'])return self::error('scope_conflict','Store does not belong to the declared business.');
        // The existing product ownership tuple, rather than a free-form store label, proves the shop.
        if($scope['product_version_id']<1||$scope['provider_mapping_id']<1)return self::error('scope_unproven','A product-owned business/store/provider tuple is required; order-only ownership is not inferred.');
        $version=$this->row(Tables::product_versions(),$scope['product_version_id']); if($version instanceof WP_Error)return $version;
        global $wpdb;
        $predicate=ProductEvidenceScope::forTable(Tables::product_versions(),$scope['shop_key']);
        $wpdb->last_error='';
        $owned=$wpdb->get_results($wpdb->prepare('SELECT id FROM '.Tables::product_versions().' WHERE id=%d AND '.$predicate.' LIMIT 2',$scope['product_version_id']),ARRAY_A);
        if(DatabaseEvidence::failed()||!is_array($owned))return self::error('scope_unavailable','Current product shop ownership could not be read.',503);
        if(count($owned)!==1)return self::error('scope_conflict','Product shop ownership is missing or ambiguous.');
        $mapping=$this->row(Tables::pod_mappings(),$scope['provider_mapping_id']); if($mapping instanceof WP_Error)return $mapping;
        if((int)$mapping['product_version_id']!==$scope['product_version_id']||(string)$mapping['environment']!==$environment
            ||($scope['variant_ref']!==''&&(string)$mapping['provider_variant_key']!==$scope['variant_ref'])
            ||(in_array($declaration['provider'],['printify','gelato'],true)&&(string)$mapping['provider']!==$declaration['provider']))return self::error('scope_conflict','Provider/product/variant/environment scope differs.');
        $ownership=(new BusinessScopeRepository())->assertActiveOwnershipForMapping($scope['provider_mapping_id']);
        if($ownership instanceof WP_Error)return $ownership;
        if(($ownership['program_key']??null)!=='PERSONALIZED_POD')return self::error('scope_conflict','Personalized POD declarations require the existing PERSONALIZED_POD program.');
        foreach(['business_id','store_id','product_version_id']as$field)if((int)$ownership[$field]!==$scope[$field])return self::error('scope_conflict','Existing ownership tuple differs from declaration scope.');
        $wpdb->last_error='';
        $claims=$wpdb->get_results($wpdb->prepare('SELECT id FROM '.Tables::pod_business_mappings().' WHERE provider_mapping_id=%d LIMIT 2',$scope['provider_mapping_id']),ARRAY_A);
        if(DatabaseEvidence::failed()||!is_array($claims))return self::error('scope_unavailable','Ownership claims could not be read.',503);
        if(count($claims)!==1)return self::error('scope_conflict','Provider ownership is ambiguous.');
        if($scope['listing_id']>0){
            $listing=$this->row(Tables::listings(),$scope['listing_id']);if($listing instanceof WP_Error)return $listing;
            if((int)$listing['product_version_id']!==$scope['product_version_id']||(string)$listing['environment']!==$environment
                ||(string)$listing['shop_reference']!==$scope['shop_key'])return self::error('scope_conflict','Listing product/environment/shop differs.');
        }
        if($scope['order_id']>0){
            $order=$this->row(Tables::orders(),$scope['order_id']);if($order instanceof WP_Error)return $order;
            if((string)$order['environment']!==$environment||(string)$order['shop_reference']!==$scope['shop_key'])return self::error('scope_conflict','Order environment/shop differs; external aliases require a separate stored identity contract.');
            $wpdb->last_error='';
            $lines=$wpdb->get_results($wpdb->prepare('SELECT id FROM '.Tables::order_line_items().' WHERE order_id=%d AND product_version_id=%d AND provider_mapping_id=%d AND environment=%s'.($scope['listing_id']>0?' AND listing_id='.(int)$scope['listing_id']:'').' LIMIT 2',
                $scope['order_id'],$scope['product_version_id'],$scope['provider_mapping_id'],$environment),ARRAY_A);
            if(DatabaseEvidence::failed()||!is_array($lines))return self::error('scope_unavailable','Order line scope could not be read.',503);
            if($lines===[])return self::error('scope_conflict','Order has no matching declared product/variant/listing line.');
        }
        return ['ownership_id'=>(int)$ownership['id'],'product_id'=>(int)$version['product_id']];
    }

    /** Same source-linked FX validation for Digital and Personalized POD declarations. */
    private function fx(array $declaration): string|WP_Error
    {
        $environment=$declaration['environment'];$fxHash='';
        if($declaration['fx_snapshot_id']>0){
            $fx=$this->row(Tables::fx_snapshots(),$declaration['fx_snapshot_id']);if($fx instanceof WP_Error)return $fx;
            try{
                $source=json_decode((string)$fx['source_metadata'],true,512,JSON_THROW_ON_ERROR);
                if(!is_array($source)||($source['kind']??null)!=='DECLARATION'||preg_match('/^[a-f0-9]{64}$/D',(string)($source['document_hash']??''))!==1)throw new \InvalidArgumentException('FX source declaration required.');
                $exact=$source['exact_fx_snapshot']??null;$originalSource=$source;unset($originalSource['exact_fx_snapshot']);
                DeclarationContract::source($originalSource);
                if(!is_array($exact)||($exact['schema']??null)!=='digiforge-finance-fx-declaration-v1'
                    ||($exact['rate']??null)!==ExactDecimal::rate((string)$fx['rate'])
                    ||!is_string($exact['source_hash']??null)||!hash_equals($exact['source_hash'],Validator::hash([
                        'environment'=>(string)$fx['environment'],'base_currency'=>(string)$fx['base_currency'],'quote_currency'=>(string)$fx['quote_currency'],
                        'rate'=>ExactDecimal::rate((string)$fx['rate']),'as_of_at'=>(string)$fx['as_of_at'],'source'=>$originalSource])))throw new \InvalidArgumentException('Exact decimal FX source binding is missing or changed.');
                $asOf=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)$fx['as_of_at'],new \DateTimeZone('UTC'));
                if(!$asOf||\DateTimeImmutable::getLastErrors()!==false||$asOf->format('Y-m-d H:i:s')!==$fx['as_of_at']||$asOf->format('Y-m-d\TH:i:s\Z')>$declaration['occurred_at'])throw new \InvalidArgumentException('FX timestamp is invalid or later than occurrence.');
                $canonical=['environment'=>(string)$fx['environment'],'base'=>(string)$fx['base_currency'],'quote'=>(string)$fx['quote_currency'],
                    'rate'=>Validator::positiveRate($fx['rate']),'as_of_at'=>(string)$fx['as_of_at'],'source'=>$source];
                if($fx['environment']!==$environment||$fx['base_currency']!==$declaration['currency']||$fx['quote_currency']!==$declaration['base_currency']
                    ||!hash_equals(Validator::hash($canonical),(string)$fx['canonical_hash'])
                    ||ExactDecimal::convert($declaration['amount'],ExactDecimal::rate((string)$fx['rate']))!==$declaration['base_amount'])throw new \InvalidArgumentException('FX reference or exact converted amount differs.');
                $fxHash=(string)$fx['canonical_hash'];
            }catch(\InvalidArgumentException|\JsonException){return self::error('fx_conflict','FX source, pair, time, hash or converted amount is invalid.');}
        }
        return $fxHash;
    }

    private function row(string $table,int $id): array|WP_Error
    {
        global $wpdb;$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE id=%d LIMIT 1',$id),ARRAY_A);
        if(DatabaseEvidence::failed())return self::error('reference_unavailable','Finance reference evidence could not be read.',503);
        return is_array($row)?$row:self::error('reference_missing','Explicit finance reference was not found.');
    }
    private static function error(string $code,string $message,int $status=409):WP_Error
    {return new WP_Error('finance_declaration_'.$code,$message,['status'=>$status,'external_execution_authorized'=>false]);}
}
