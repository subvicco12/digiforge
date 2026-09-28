<?php
declare(strict_types=1);
namespace DigiForge\Orders;

use DigiForge\Database\Tables;

/** Read-only reconciliation of persisted order evidence against current local line-item state. */
final class ReconciliationReadModel
{
    /** @return array<string,mixed> */
    public function forOrder(int $orderId):array
    {
        global $wpdb;
        $order=$wpdb->get_row($wpdb->prepare('SELECT id,external_order_reference,shop_reference,state FROM '.Tables::orders().' WHERE id=%d LIMIT 1',$orderId),ARRAY_A);
        if(!empty($wpdb->last_error))return ['order_id'=>$orderId,'query_state'=>'UNAVAILABLE','reconciled'=>false,'reason'=>'ORDER_READ_UNAVAILABLE','line_item_count'=>null,'invalid_line_items'=>null,'unmapped_pod_line_items'=>null,'fulfillment_authorized'=>false,'external_execution_performed'=>false];
        if(!is_array($order))return ['order_id'=>$orderId,'query_state'=>'AVAILABLE','reconciled'=>false,'reason'=>'ORDER_NOT_FOUND','line_item_count'=>null,'invalid_line_items'=>null,'unmapped_pod_line_items'=>null,'fulfillment_authorized'=>false,'external_execution_performed'=>false];
        $lineCount=$this->count($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::order_line_items().' WHERE order_id=%d',$orderId));
        $invalid=$this->count($wpdb->prepare("SELECT COUNT(*) FROM ".Tables::order_line_items()." WHERE order_id=%d AND validation_status<>'VALIDATED'",$orderId));
        $unmapped=$this->count($wpdb->prepare("SELECT COUNT(*) FROM ".Tables::order_line_items()." li WHERE li.order_id=%d AND li.provider_mapping_id=0 AND NOT EXISTS (SELECT 1 FROM ".Tables::digital_products()." dp WHERE dp.product_version_id=li.product_version_id)",$orderId));
        if($lineCount===null||$invalid===null||$unmapped===null)return ['order_id'=>$orderId,'external_order_reference'=>(string)$order['external_order_reference'],'shop_reference'=>(string)$order['shop_reference'],'query_state'=>'UNAVAILABLE','reconciled'=>false,'reason'=>'RECONCILIATION_EVIDENCE_UNAVAILABLE','line_item_count'=>null,'invalid_line_items'=>null,'unmapped_pod_line_items'=>null,'fulfillment_authorized'=>false,'external_execution_performed'=>false];
        return ['order_id'=>$orderId,'external_order_reference'=>(string)$order['external_order_reference'],'shop_reference'=>(string)$order['shop_reference'],'query_state'=>'AVAILABLE','line_item_count'=>$lineCount,'invalid_line_items'=>$invalid,'unmapped_pod_line_items'=>$unmapped,'reconciled'=>$lineCount>0&&$invalid===0&&$unmapped===0,'fulfillment_authorized'=>false,'external_execution_performed'=>false];
    }

    /** @return array{query_state:string,items:list<array<string,mixed>>} */
    public function discrepancyProjection(int $orderId,int $limit=25):array
    {
        global $wpdb;$limit=max(1,min(100,$limit));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,product_version_id,validation_status,provider_mapping_id FROM ".Tables::order_line_items()." WHERE order_id=%d AND (validation_status<>'VALIDATED' OR (provider_mapping_id=0 AND NOT EXISTS (SELECT 1 FROM ".Tables::digital_products()." dp WHERE dp.product_version_id=".Tables::order_line_items().".product_version_id))) ORDER BY id ASC LIMIT %d",$orderId,$limit),ARRAY_A);
        if(!is_array($rows)||!empty($wpdb->last_error))return ['query_state'=>'UNAVAILABLE','items'=>[]];
        return ['query_state'=>'AVAILABLE','items'=>array_map(static function(array $r):array{$r['read_only']=true;$r['fulfillment_authorized']=false;$r['retry_permitted']=false;$r['external_execution_authorized']=false;return $r;},$rows)];
    }

    /** @return list<array<string,mixed>> */
    public function discrepancies(int $orderId,int $limit=25):array
    {
        return $this->discrepancyProjection($orderId,$limit)['items'];
    }

    private function count(string $sql):?int
    {
        global $wpdb;
        $value=$wpdb->get_var($sql);
        return is_numeric($value)&&empty($wpdb->last_error)?(int)$value:null;
    }
}
