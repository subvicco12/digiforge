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
        if(!is_array($order))return ['order_id'=>$orderId,'reconciled'=>false,'reason'=>'ORDER_NOT_FOUND','external_execution_performed'=>false];
        $lineCount=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::order_line_items().' WHERE order_id=%d',$orderId));
        $invalid=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".Tables::order_line_items()." WHERE order_id=%d AND validation_status<>'VALIDATED'",$orderId));
        $unmapped=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".Tables::order_line_items()." li WHERE li.order_id=%d AND li.provider_mapping_id=0 AND NOT EXISTS (SELECT 1 FROM ".Tables::digital_products()." dp WHERE dp.product_version_id=li.product_version_id)",$orderId));
        return ['order_id'=>$orderId,'external_order_reference'=>(string)$order['external_order_reference'],'shop_reference'=>(string)$order['shop_reference'],'line_item_count'=>$lineCount,'invalid_line_items'=>$invalid,'unmapped_pod_line_items'=>$unmapped,'reconciled'=>$lineCount>0&&$invalid===0&&$unmapped===0,'fulfillment_authorized'=>false,'external_execution_performed'=>false];
    }
}
