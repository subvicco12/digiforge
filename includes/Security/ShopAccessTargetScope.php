<?php
declare(strict_types=1);
namespace DigiForge\Security;

use DigiForge\Database\Tables;
use DigiForge\Portal\ProductEvidenceScope;
use DigiForge\Listings\EtsyStoredShopScope;

/** Minimal stored authorization identities; never returns customer or source documents. */
final class ShopAccessTargetScope
{
    public static function forRequest(\WP_REST_Request $request, array $allowed): string|false|\WP_Error
    {
        $route=$request->get_route();$id=$request->get_param('id');$table=null;
        if (str_starts_with($route,'/digiforge/v1/printify/catalog-read/')) {
            $catalog=new \DigiForge\POD\PrintifyCatalogReadRepository();
            if($route==='/digiforge/v1/printify/catalog-read/grants'&&$request->get_method()==='POST'){
                try{$body=json_decode($request->get_body(),true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){return self::error(400);}
                if(!is_array($body))return self::error(400);
                $shop=$catalog->targetShopForInput($body);
            }elseif(preg_match('#^/digiforge/v1/printify/catalog-read/([a-f0-9]{64})(?:/(?:revoke|safety-exception(?:/revoke)?|evidence/[a-f0-9]{64}))?$#D',$route,$catalogRoute)){
                $shop=$catalog->targetShopForGrant($catalogRoute[1]);
            }else{return self::error();}
            if($shop instanceof \WP_Error)return $shop;
            if(!in_array($shop,$allowed,true))return self::error();
            foreach(['shop','shop_key'] as $field){$claim=$request->get_param($field);if($claim!==null&&$claim!==$shop)return self::error();}
            return $shop;
        }
        if (preg_match('#^/digiforge/v1/reviewed-contracts/(digital|personalized_pod|standard_pod|jewelry)/(policy|ownership)(/decision)?$#D',$route,$contract)) {
            // The registered URL, never a query/body shop claim, establishes this scope.
            return in_array($contract[1],$allowed,true)?$contract[1]:self::error();
        }
        // These existing endpoints persist/inspect the exact typed shop passed to their
        // repositories; charges and usage additionally verify their stored parent there.
        if (($request->get_method()==='GET' && ($route==='/digiforge/v1/ai/commercial-kpis'||preg_match('#^/digiforge/v1/ai/evidence/usage/[1-9][0-9]*$#D',$route))) ||
            ($request->get_method()==='POST' && preg_match('#^/digiforge/v1/ai/evidence/(quotes|tariffs|charges|attributions)$#D',$route))) {
            $shop=$request->get_param('shop_key');
            if (!is_string($shop)||!ShopAccessPolicy::validShop($shop)) return self::error(400);
            return in_array($shop,$allowed,true)?$shop:self::error();
        }
        if (preg_match('#^/digiforge/v1/orders/[1-9][0-9]*/readiness$#D',$route)) $table=Tables::orders();
        elseif (preg_match('#^/digiforge/v1/orders/(order|plan|intent)/[1-9][0-9]*/state$#D',$route,$m)) $table=match($m[1]){'order'=>Tables::orders(),'plan'=>Tables::fulfillment_plans(),'intent'=>Tables::fulfillment_intents()};
        elseif (preg_match('#^/digiforge/v1/listings/[1-9][0-9]*/readiness$#D',$route)) $table=Tables::listings();
        elseif (preg_match('#^/digiforge/v1/research/candidates/[1-9][0-9]*/(review|promote|retry-product-factory)$#D',$route)) $table=Tables::research_candidates();
        elseif (preg_match('#^/digiforge/v1/pod/mappings/[1-9][0-9]*/readiness$#D',$route)) $table=Tables::pod_mappings();
        elseif (preg_match('#^/digiforge/v1/(opportunities|product-families|products|product-versions)/[1-9][0-9]*(/state)?$#D',$route,$m)) $table=match($m[1]){'opportunities'=>Tables::opportunities(),'product-families'=>Tables::product_families(),'products'=>Tables::products(),'product-versions'=>Tables::product_versions()};
        if ($table===null) return false;
        if (!is_scalar($id)||!preg_match('/^[1-9][0-9]*$/D',(string)$id)) return self::error();
        global $wpdb;$matches=[];
        foreach (ShopAccessPolicy::canonicalShops() as $shop) {
            // Only granted scopes are queried. An inaccessible target cannot establish any
            // private identity, even when the caller changes its selected-shop parameter.
            if (!in_array($shop,$allowed,true)) continue;
            $predicate=self::predicate($table,$shop);if ($predicate===null) return false;
            $wpdb->last_error='';
            $found=$wpdb->get_var($wpdb->prepare('SELECT '.$table.'.id FROM '.$table.' WHERE '.$table.'.id=%d AND '.$predicate.' LIMIT 1',(int)$id));
            if (self::databaseFailed()) return self::error(503);
            if ($found!==null) $matches[]=$shop;
        }
        if (count($matches)!==1) return self::error();
        foreach (['shop_key','shop'] as $key) {
            $claimed=$request->get_param($key);
            if ($claimed!==null && (!is_string($claimed)||$claimed!==$matches[0])) return self::error();
        }
        return $matches[0];
    }

    private static function predicate(string $table,string $shop): ?string
    {
        global $wpdb;
        if ($table===Tables::orders()) return EtsyStoredShopScope::orderPredicate($shop,$table);
        if ($table===Tables::listings()) return $wpdb->prepare('BINARY '.$table.'.shop_reference=BINARY %s',$shop);
        if ($table===Tables::research_candidates()) return $wpdb->prepare("CASE WHEN JSON_VALID({$table}.score_inputs) THEN BINARY JSON_UNQUOTE(JSON_EXTRACT({$table}.score_inputs,'$.shop_key'))=BINARY %s ELSE false END",$shop);
        if ($table===Tables::fulfillment_plans()) return 'EXISTS (SELECT 1 FROM '.Tables::orders().' acl_order WHERE acl_order.id='.$table.'.order_id AND '.EtsyStoredShopScope::orderPredicate($shop,'acl_order').')';
        // Unbound intent variants remain global until their exact parent contract is resolved.
        if ($table===Tables::fulfillment_intents()) return null;
        return ProductEvidenceScope::forTable($table,$shop);
    }

    /** Reads the mutable WPDB error after a query.
     * @phpstan-impure
     */
    private static function databaseFailed():bool {global $wpdb;return $wpdb->last_error!=='';}

    private static function error(int $status=403): \WP_Error
    {
        if (!Logger::write('shop_access_target_denied',['status'=>$status,'actor_id'=>get_current_user_id()],'shop_access','target')) return new \WP_Error('digiforge_shop_access_audit','Access denial audit unavailable.',['status'=>503]);
        return new \WP_Error('digiforge_shop_access_scope','The exact stored target is unavailable in the granted scope.',['status'=>$status]);
    }
}
