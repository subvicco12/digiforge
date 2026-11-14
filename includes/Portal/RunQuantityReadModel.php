<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Database\Tables;
use DigiForge\Security\ShopAccess;

/** Stored item counts only; no provider or production authority. */
final class RunQuantityReadModel
{
    public function snapshot(?string $shop=null,int $limit=50):array
    {
        $result=['query_state'=>'UNAVAILABLE','items'=>[],'unattributed_quantity'=>null,'truncated'=>false,'read_only'=>true,'external_execution_authorized'=>false];
        $shops=array_keys(ShopOperationsReadModel::shops());$shops=array_values(array_diff($shops,['all']));
        if(!is_user_logged_in()||!current_user_can('manage_digiforge'))return $result;
        $required=$shop===null?$shops:[$shop];
        foreach($required as $key)if(!in_array($key,$shops,true)||is_wp_error(ShopAccess::authorize($key,'manage_digiforge_ai')))return $result;
        global $wpdb;$limit=max(1,min(100,$limit));$where=$wpdb->prepare(' WHERE BINARY shop_key IN ('.implode(',',array_fill(0,count($required),'%s')).')',...$required);
        $wpdb->last_error='';
        $unknown=$wpdb->get_row('SELECT COUNT(*) records,COALESCE(SUM(quantity),0) quantity,MIN(quantity) minimum_quantity FROM '.Tables::shop_ai_usage().$where." AND (run_id='' OR run_started_at IS NULL)",ARRAY_A);
        if(!is_array($unknown)||$wpdb->last_error!==''||!self::countValue((string)$unknown['quantity'])||((int)$unknown['records']>0&&(int)$unknown['minimum_quantity']<1))return $result;
        $unknownQuantity=(int)$unknown['quantity'];
        $wpdb->last_error='';
        $rows=$wpdb->get_results('SELECT BINARY shop_key shop_key,BINARY workflow workflow,BINARY stage stage,BINARY run_id run_id,run_started_at,SUM(quantity) quantity,MIN(quantity) minimum_quantity FROM '.Tables::shop_ai_usage().$where." AND run_id<>'' AND run_started_at IS NOT NULL GROUP BY BINARY shop_key,BINARY workflow,BINARY stage,BINARY run_id,run_started_at ORDER BY run_started_at DESC,shop_key,workflow,stage,run_id LIMIT ".($limit+1),ARRAY_A);
        if(!is_array($rows)||$wpdb->last_error!==''||count($rows)>$limit+1)return $result;
        $items=[];
        foreach($rows as $row){
            $quantity=(string)$row['quantity'];
            if(!self::countValue($quantity)||(int)$quantity<1||(int)$row['minimum_quantity']<1)return $result;
            $id=(string)$row['run_id'];$started=(string)($row['run_started_at']??'');
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$started,new \DateTimeZone('UTC'));
            if($id===''||strlen($id)>100||sanitize_key($id)!==$id||$date===false||$date->format('Y-m-d H:i:s')!==$started)return $result;
            $items[]=['shop_key'=>(string)$row['shop_key'],'workflow'=>(string)$row['workflow'],'stage'=>(string)$row['stage'],'started_at'=>$started,'quantity'=>(int)$quantity,'run_fingerprint'=>hash('sha256',$id."\0".$started)];
        }
        $result['query_state']='AVAILABLE';$result['unattributed_quantity']=$unknownQuantity;$result['truncated']=count($items)>$limit;$result['items']=array_slice($items,0,$limit);return $result;
    }
    private static function countValue(string $value):bool{return ctype_digit($value)&&(string)(int)$value===$value;}
}
