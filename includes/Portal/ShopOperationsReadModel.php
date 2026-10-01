<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Database\Tables;
use DigiForge\POD\MasterCatalogV2Reference;
use DigiForge\POD\PersonalizedCatalogReference;

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
        $catalogKey=$shop==='personalized_pod'?MasterCatalogV2Reference::CATALOG_KEY:null;
        if($catalogKey===null){
            $catalog=null;
        }else{
            $wpdb->last_error='';
            $catalog=$wpdb->get_row(
                $wpdb->prepare('SELECT id,catalog_key,version_label,source_sha256,source_state,parent_version_id,migration_metadata,row_count,fingerprint,production_authority,created_at FROM '.Tables::catalog_versions().' WHERE catalog_key=%s ORDER BY id DESC LIMIT 1',$catalogKey),
                ARRAY_A
            );
        }
        $catalogState=(!empty($wpdb->last_error))?'UNAVAILABLE':'AVAILABLE';
        $wpdb->last_error='';
        $policyWhere=$shop===self::ALL?'':$wpdb->prepare(' WHERE shop_key=%s',$shop);
        $policies=$wpdb->get_results(
            'SELECT shop_key,environment,currency,state,policy_hash,updated_at FROM '.Tables::shop_ai_policies().$policyWhere.' ORDER BY shop_key,environment',
            ARRAY_A
        );
        $policiesState=(is_array($policies)&&empty($wpdb->last_error))?'AVAILABLE':'UNAVAILABLE';
        $wpdb->last_error='';
        $usageWhere=$shop===self::ALL?'':$wpdb->prepare(' WHERE shop_key=%s',$shop);
        $usage=$wpdb->get_results(
            'SELECT shop_key,workflow,stage,model_key,SUM(quantity) quantity,SUM(estimated_cost) estimated_cost,SUM(actual_cost) actual_cost,MAX(occurred_at) last_used_at FROM '.Tables::shop_ai_usage().$usageWhere.' GROUP BY shop_key,workflow,stage,model_key ORDER BY shop_key,workflow,stage,model_key',
            ARRAY_A
        );
        $usageState=(is_array($usage)&&empty($wpdb->last_error))?'AVAILABLE':'UNAVAILABLE';
        return [
            'shop'=>$shop,
            'shop_label'=>self::shops()[$shop],
            'query_state'=>['catalog'=>$catalogState,'ai_policies'=>$policiesState,'ai_usage'=>$usageState],
            'catalog'=>$catalogState==='AVAILABLE'&&is_array($catalog)?self::catalogEvidence($catalog):[],
            'ai_policies'=>$policiesState==='AVAILABLE'?$policies:[],
            'ai_usage'=>$usageState==='AVAILABLE'?$usage:[],
            'external_execution_performed'=>false,
        ];
    }

    /** @param array<string,mixed> $catalog @return array<string,mixed> */
    private static function catalogEvidence(array $catalog):array
    {
        $migration=json_decode((string)($catalog['migration_metadata']??''),true);
        $catalog['migration_metadata_valid']=self::validV2MigrationEvidence($catalog,$migration);
        $catalog['migration_metadata']=is_array($migration)?$migration:[];
        $catalog['is_migration_candidate']=(int)($catalog['parent_version_id']??0)>0;
        $catalog['production_authority']=false;
        $catalog['promotion_authorized']=false;
        return $catalog;
    }

    private static function validV2MigrationEvidence(array $catalog,mixed $migration):bool
    {
        return is_array($migration)
            &&(string)($catalog['catalog_key']??'')===MasterCatalogV2Reference::CATALOG_KEY
            &&hash_equals(MasterCatalogV2Reference::SOURCE_SHA256,(string)($catalog['source_sha256']??''))
            &&(string)($catalog['source_state']??'')==='MIGRATION_CANDIDATE'
            &&(int)($catalog['parent_version_id']??0)>0
            &&(int)($catalog['row_count']??0)===MasterCatalogV2Reference::ROW_COUNT
            &&preg_match('/^[a-f0-9]{64}$/',(string)($catalog['fingerprint']??''))===1
            &&(int)($catalog['production_authority']??1)===0
            &&(string)($migration['source_state']??'')==='MIGRATION_CANDIDATE'
            &&hash_equals(PersonalizedCatalogReference::CATALOG_KEY,(string)($migration['parent_catalog_key']??''))
            &&(int)($migration['parent_version_id']??0)===(int)$catalog['parent_version_id']
            &&hash_equals(MasterCatalogV2Reference::SOURCE_FILE,(string)($migration['source_file']??''))
            &&hash_equals(MasterCatalogV2Reference::SOURCE_SHA256,(string)($migration['source_sha256']??''))
            &&hash_equals((string)$catalog['fingerprint'],(string)($migration['fingerprint']??''))
            &&empty($migration['production_authority'])
            &&empty($migration['promotion_authorized']);
    }

    /** @return list<array<string,mixed>> */
    public function catalogItems(int $versionId,array $filters=[],int $limit=50, ?string &$queryState=null):array
    {
        global $wpdb;
        if($versionId<1){$queryState='AVAILABLE';return [];}
        $where=['catalog_version_id=%d'];$args=[$versionId];
        foreach(['family','engine','template_state'] as $field){
            $value=trim((string)($filters[$field]??''));
            if($value!==''){$where[]=$field.'=%s';$args[]=$value;}
        }
        $limit=max(1,min(100,$limit));$args[]=$limit;
        $sql='SELECT listing_id,family,concept,engine,physical_product,supplier_gate,template_state,attributes,row_hash FROM '.Tables::catalog_items().' WHERE '.implode(' AND ',$where).' ORDER BY listing_id ASC LIMIT %d';
        $rows=$wpdb->get_results($wpdb->prepare($sql,...$args),ARRAY_A);
        if(!is_array($rows)||!empty($wpdb->last_error)){$queryState='UNAVAILABLE';return [];}
        $queryState='AVAILABLE';return $rows;
    }
}
