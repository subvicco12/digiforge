<?php
declare(strict_types=1);
namespace DigiForge\Listings;

use DigiForge\Database\Tables;

/** Read-only webhook processing/reconciliation evidence. */
final class WebhookReconciliationReadModel
{
    /** @return array<string,mixed> */
    public function snapshot(string $provider='etsy',int $limit=50,?string $shop=null):array
    {
        global $wpdb;$provider=sanitize_key($provider);$limit=max(1,min(100,$limit));
        $scope='';if($shop!==null){if(!in_array($shop,\DigiForge\Security\ShopAccessPolicy::canonicalShops(),true))return ['provider'=>$provider,'evidence_state'=>'UNAVAILABLE','counts'=>[],'events'=>[],'discrepancies'=>[],'retry_recommended'=>false,'retry_performed'=>false,'external_execution_performed'=>false];
            $local=$wpdb->prepare('%s',$shop);$orders=Tables::orders();$identity=EtsyStoredShopScope::matches($local,'scope_order.shop_reference','scope_order.environment');$scope=" AND (BINARY shop_reference=BINARY $local OR EXISTS (SELECT 1 FROM $orders scope_order WHERE BINARY scope_order.channel=BINARY 'etsy' AND BINARY scope_order.shop_reference=BINARY ".Tables::webhook_evidence().".shop_reference AND BINARY scope_order.idempotency_key=BINARY CONCAT('etsy_webhook:',".Tables::webhook_evidence().".event_id) AND $identity))";
        }
        $wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT id,event_id,event_type,shop_reference,verification_status,processing_status,result_hash,received_at,processed_at FROM '.Tables::webhook_evidence().' WHERE provider=%s'.$scope.' ORDER BY id DESC LIMIT %d',$provider,$limit),ARRAY_A);if(!empty($wpdb->last_error))return ['provider'=>$provider,'evidence_state'=>'UNAVAILABLE','counts'=>['verified'=>0,'processed'=>0,'failed'=>0,'unresolved'=>0,'discrepancies'=>0],'events'=>[],'discrepancies'=>[],'retry_recommended'=>false,'retry_performed'=>false,'external_execution_performed'=>false];$rows=is_array($rows)?$rows:[];
        $counts=['verified'=>0,'processed'=>0,'failed'=>0,'unresolved'=>0,'discrepancies'=>0];$discrepancies=[];$idempotency=$wpdb->prefix.'digiforge_idempotency';
        foreach($rows as &$row){$status=strtoupper((string)$row['processing_status']);if($status==='PROCESSED')$counts['processed']++;elseif($status==='FAILED')$counts['failed']++;elseif($status==='VERIFIED')$counts['verified']++;else $counts['unresolved']++;
            $key='etsy_webhook:'.hash('sha256',(string)$row['event_id']);$wpdb->last_error='';$dedup=$wpdb->get_row($wpdb->prepare("SELECT status,response_hash FROM {$idempotency} WHERE operation_key=%s AND operation_type=%s LIMIT 1",$key,'ETSY_WEBHOOK'),ARRAY_A);$reasons=[];$dedupUnavailable=!empty($wpdb->last_error);
            if($dedupUnavailable)$reasons[]='DEDUP_EVIDENCE_UNAVAILABLE';elseif(!is_array($dedup))$reasons[]='DEDUP_EVIDENCE_MISSING';else{$dedupStatus=strtoupper((string)($dedup['status']??''));if($status==='PROCESSED'&&$dedupStatus!=='PROCESSED')$reasons[]='PROCESSING_STATUS_MISMATCH';if($status!=='PROCESSED'&&$dedupStatus==='PROCESSED')$reasons[]='DEDUP_PROCESSED_WITHOUT_EVIDENCE';$resultHash=strtolower(trim((string)($row['result_hash']??'')));$responseHash=strtolower(trim((string)($dedup['response_hash']??'')));if($status==='PROCESSED'&&$resultHash!==''&&$responseHash!==''&&!hash_equals($resultHash,$responseHash))$reasons[]='RESULT_HASH_MISMATCH';}
            $row['reconciliation']=['consistent'=>$reasons===[],'reasons'=>$reasons,'retry_recommended'=>false,'retry_performed'=>false];if($reasons!==[]){$counts['discrepancies']++;$discrepancies[]=['event_id'=>(string)$row['event_id'],'reasons'=>$reasons];}
        }unset($row);
        return ['provider'=>$provider,'shop_scope'=>$shop??'all','scope_basis'=>'Exact canonical receipt or matching stored Etsy order/event and environment-bound identity; unresolved ownership is excluded, never inferred.','evidence_state'=>'AVAILABLE','counts'=>$counts,'events'=>$rows,'discrepancies'=>$discrepancies,'retry_recommended'=>false,'retry_performed'=>false,'external_execution_performed'=>false];
    }
}
