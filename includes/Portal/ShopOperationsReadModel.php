<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Database\Tables;

/** Read-only operational projection for v6 shop-scoped portal views. */
final class ShopOperationsReadModel
{
    public const ALL='all';
    /** @return array<string,string> */
    public static function shops():array{return[
        self::ALL=>'All Shops',
        'digital'=>'Digital',
        'personalized_pod'=>'Personalized POD',
        'standard_pod'=>'Standard POD',
        'jewelry'=>'Jewelry',
    ];}

    public static function normalize(string $shop):string
    {
        $shop=sanitize_key($shop);
        return isset(self::shops()[$shop])?$shop:self::ALL;
    }

    /** @return array<string,mixed> */
    public function snapshot(string $shop=self::ALL):array
    {
        global $wpdb;
        $shop=self::normalize($shop);
        $catalog=$wpdb->get_row(
            'SELECT id,catalog_key,version_label,source_sha256,source_state,parent_version_id,migration_metadata,row_count,fingerprint,production_authority,created_at FROM '.Tables::catalog_versions().' ORDER BY id DESC LIMIT 1',
            ARRAY_A
        );
        $policyWhere=$shop===self::ALL?'':$wpdb->prepare(' WHERE shop_key=%s',$shop);
        $policies=$wpdb->get_results(
            'SELECT shop_key,environment,currency,state,policy_hash,updated_at FROM '.Tables::shop_ai_policies().$policyWhere.' ORDER BY shop_key,environment',
            ARRAY_A
        );
        $usageWhere=$shop===self::ALL?'':$wpdb->prepare(' WHERE shop_key=%s',$shop);
        $usage=$wpdb->get_results(
            'SELECT shop_key,workflow,stage,model_key,SUM(quantity) quantity,SUM(estimated_cost) estimated_cost,SUM(actual_cost) actual_cost,MAX(occurred_at) last_used_at FROM '.Tables::shop_ai_usage().$usageWhere.' GROUP BY shop_key,workflow,stage,model_key ORDER BY shop_key,workflow,stage,model_key',
            ARRAY_A
        );
        return [
            'shop'=>$shop,
            'shop_label'=>self::shops()[$shop],
            'catalog'=>is_array($catalog)?$catalog:[],
            'ai_policies'=>is_array($policies)?$policies:[],
            'ai_usage'=>is_array($usage)?$usage:[],
            'external_execution_performed'=>false,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function catalogItems(int $versionId,array $filters=[],int $limit=50):array
    {
        global $wpdb;
        if($versionId<1)return [];
        $where=['catalog_version_id=%d'];$args=[$versionId];
        foreach(['family','engine','template_state'] as $field){
            $value=trim((string)($filters[$field]??''));
            if($value!==''){$where[]=$field.'=%s';$args[]=$value;}
        }
        $limit=max(1,min(100,$limit));$args[]=$limit;
        $sql='SELECT listing_id,family,concept,engine,physical_product,supplier_gate,template_state,attributes,row_hash FROM '.Tables::catalog_items().' WHERE '.implode(' AND ',$where).' ORDER BY listing_id ASC LIMIT %d';
        $rows=$wpdb->get_results($wpdb->prepare($sql,...$args),ARRAY_A);
        return is_array($rows)?$rows:[];
    }
}
