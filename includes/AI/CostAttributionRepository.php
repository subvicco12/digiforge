<?php
declare(strict_types=1);
namespace DigiForge\AI;
use DigiForge\Database\Tables;
use WP_Error;

/** Append-only reviewed lineage for products/orders established after generation. */
final class CostAttributionRepository
{
    public function review(int $usageId,string $shop,array $target,string $decision):array|WP_Error
    {
        if(!current_user_can('manage_digiforge_ai')||get_current_user_id()<1)return self::error('reviewer_required','Authorized AI cost reviewer required.',403);
        if($decision!=='CONFIRM_COST_ATTRIBUTION'||$shop===''||sanitize_key($shop)!==$shop)return self::error('decision_required','Exact shop and explicit cost attribution review required.');
        global $wpdb;$lock=ShopAiGovernanceRepository::generationLockName($shop);$wpdb->last_error='';$acquired=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock));if($wpdb->last_error!==''||(string)$acquired!=='1')return self::error('busy','Shop attribution accounting is busy.',503);
        try{$result=$this->reviewLocked($usageId,$shop,$target);}catch(\Throwable){$result=self::error('uncertain','Attribution persistence is uncertain; do not retry automatically.',503);}finally{$wpdb->last_error='';$released=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));$uncertain=$wpdb->last_error!==''||(string)$released!=='1';}return $uncertain?self::error('uncertain','Attribution accounting lock release uncertain.',503):$result;
    }
    private function reviewLocked(int $usageId,string $shop,array $target):array|WP_Error
    {
        global $wpdb;$wpdb->last_error='';$usage=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::shop_ai_usage().' WHERE id=%d AND shop_key=%s',$usageId,$shop),ARRAY_A);if($wpdb->last_error!==''||!is_array($usage)||!str_starts_with((string)$usage['idempotency_key'],'generation-'))return self::error('scope_unavailable','Exact original provider reservation required.');
        $attribution=(new MonetaryReservationRepository())->validateAttribution($shop,$target);if($attribution instanceof WP_Error)return $attribution;if($attribution['product_id']<1&&$attribution['order_id']<1)return self::error('target_required','A verified stored product or order is required.');
        foreach(['product_id','order_id']as $field)if((int)$usage[$field]>0&&(int)$usage[$field]!==$attribution[$field])return self::error('conflict','Original nonempty attribution cannot be reassigned.');
        $proof=['usage_id'=>$usageId,'shop_key'=>$shop,'run_id'=>$usage['run_id'],'workflow'=>$usage['workflow'],'stage'=>$usage['stage']]+$attribution+['external_execution_authorized'=>false];$old=$this->forUsage($usageId,$shop);if($old instanceof WP_Error)return $old;
        if($old!==null){$identity=$old;unset($identity['reviewed_by'],$identity['reviewed_at']);return $identity===$proof?$old+['idempotent_replay'=>true]:self::error('conflict','Reviewed attribution cannot be overwritten.');}
        $saved=$proof+['reviewed_by'=>get_current_user_id(),'reviewed_at'=>current_time('mysql',true)];$wpdb->last_error='';$wpdb->insert($wpdb->options,['option_name'=>'digiforge_ai_attribution_'.$usageId,'option_value'=>wp_json_encode($saved),'autoload'=>'no']);$confirmed=$this->forUsage($usageId,$shop);return $confirmed===$saved?$saved:self::error('uncertain','Attribution receipt could not be confirmed.',503);
    }
    public function forUsage(int $usageId,string $shop):array|WP_Error|null
    {
        global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s','digiforge_ai_attribution_'.$usageId));if($wpdb->last_error!=='')return self::error('unavailable','Reviewed attribution evidence unavailable.',503);if($raw===null)return null;$row=json_decode((string)$raw,true);return is_array($row)&&($row['usage_id']??null)===$usageId&&($row['shop_key']??null)===$shop?$row:self::error('conflict','Reviewed attribution evidence conflicts with this reservation.');
    }
    private static function error(string $code,string $message,int $status=409):WP_Error{return new WP_Error('ai_attribution_'.$code,$message,['status'=>$status,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
}
