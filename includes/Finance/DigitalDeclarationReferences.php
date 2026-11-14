<?php
declare(strict_types=1);
namespace DigiForge\Finance;

use DigiForge\Database\Tables;
use DigiForge\Database\QueryEvidence;
use DigiForge\Portal\ProductEvidenceScope;
use WP_Error;

/** Existing Digital research ancestry plus a signed source relation, with no POD prerequisite. */
final class DigitalDeclarationReferences
{
    public function validate(array $declaration):array|WP_Error
    {
        $scope=$declaration['scope'];$environment=$declaration['environment'];
        if($scope['shop_key']!=='digital'||$declaration['provider']!=='etsy'||$scope['provider_mapping_id']!==0||$scope['variant_ref']!==''||$scope['product_version_id']<1)return self::error('unsupported_scope');
        $binding=(new DigitalSourceBinding())->current($scope['business_id'],$scope['store_id'],$environment);if($binding instanceof WP_Error)return $binding;
        global$wpdb;$predicate=ProductEvidenceScope::forTable(Tables::product_versions(),'digital');
        $wpdb->last_error='';$versions=$wpdb->get_results($wpdb->prepare('SELECT id,product_id FROM '.Tables::product_versions().' WHERE id=%d AND '.$predicate.' LIMIT 2',$scope['product_version_id']),ARRAY_A);
        if(QueryEvidence::failed()||!is_array($versions))return self::error('unavailable',503);
        if(count($versions)!==1)return self::error('product_scope');
        if($scope['listing_id']>0){
            $listing=$this->row(Tables::listings(),$scope['listing_id']);if($listing instanceof WP_Error)return $listing;
            if((int)$listing['product_version_id']!==$scope['product_version_id']||!self::sameShop($listing,$environment,$binding))return self::error('listing_scope');
        }
        if($scope['order_id']>0){
            $order=$this->row(Tables::orders(),$scope['order_id']);if($order instanceof WP_Error)return $order;
            if(!self::sameShop($order,$environment,$binding))return self::error('order_scope');
            $wpdb->last_error='';$lines=$wpdb->get_results($wpdb->prepare('SELECT id FROM '.Tables::order_line_items().' WHERE order_id=%d AND product_version_id=%d AND provider_mapping_id=0 AND BINARY environment=BINARY %s'.($scope['listing_id']>0?' AND listing_id='.(int)$scope['listing_id']:'').' LIMIT 2',$scope['order_id'],$scope['product_version_id'],$environment),ARRAY_A);
            if(QueryEvidence::failed()||!is_array($lines))return self::error('unavailable',503);
            if(count($lines)!==1)return self::error('order_line_scope');
        }
        $again=(new DigitalSourceBinding())->current($scope['business_id'],$scope['store_id'],$environment);
        if($again instanceof WP_Error)return $again;if($again!==$binding)return self::error('changed');
        return $binding+['product_id'=>(int)$versions[0]['product_id']];
    }
    private static function sameShop(array $row,string $environment,array $binding):bool
    {
        return $row['environment']===$environment&&$row['channel']==='etsy'&&in_array($row['shop_reference'],['digital',$binding['external_shop_reference']],true);
    }
    private function row(string $table,int $id):array|WP_Error
    {
        global$wpdb;$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE id=%d LIMIT 1',$id),ARRAY_A);
        if(QueryEvidence::failed())return self::error('unavailable',503);return is_array($row)?$row:self::error('missing');
    }
    private static function error(string $code,int $status=409):WP_Error{return new WP_Error('finance_digital_reference_'.$code,'Exact Digital financial product/source scope is required.',['status'=>$status,'external_execution_authorized'=>false]);}
}
