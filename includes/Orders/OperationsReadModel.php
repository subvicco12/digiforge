<?php
declare(strict_types=1);
namespace DigiForge\Orders;

use DigiForge\Database\Tables;

/** Read-only order readiness and human-authorization projection. */
final class OperationsReadModel
{
    /** @return list<array<string,mixed>> */
    public function recent(int $limit=50, ?string &$queryState=null):array
    {
        global $wpdb;$limit=max(1,min(100,$limit));
        $wpdb->last_error='';
        $orders=$wpdb->get_results($wpdb->prepare('SELECT id,channel,environment,external_order_reference,shop_reference,currency,total_amount,personalization_required,state,approved_by,approved_at,created_at,updated_at FROM '.Tables::orders().' ORDER BY id DESC LIMIT %d',$limit),ARRAY_A);
        if(!is_array($orders)||!empty($wpdb->last_error)){$queryState='UNAVAILABLE';return [];}
        $queryState='AVAILABLE';
        $repo=new Repository();$out=[];
        foreach($orders as $order){$readiness=$repo->readiness((int)$order['id']);$out[]=$order+[
            'readiness'=>is_wp_error($readiness)?['ready'=>false,'error'=>$readiness->get_error_code()]:$readiness,
            'reconciliation'=>(new ReconciliationReadModel())->forOrder((int)$order['id']),
            'external_fulfillment_authorized'=>false,
            'external_execution_performed'=>false,
        ];}
        return $out;
    }
}
