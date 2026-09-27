<?php
declare(strict_types=1);
namespace DigiForge\Listings;

use DigiForge\Database\Tables;

/** Read-only webhook processing/reconciliation evidence. */
final class WebhookReconciliationReadModel
{
    /** @return array<string,mixed> */
    public function snapshot(string $provider='etsy',int $limit=50):array
    {
        global $wpdb;$provider=sanitize_key($provider);$limit=max(1,min(100,$limit));
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,event_id,event_type,shop_reference,verification_status,processing_status,result_hash,received_at,processed_at FROM '.Tables::webhook_evidence().' WHERE provider=%s ORDER BY id DESC LIMIT %d',$provider,$limit),ARRAY_A)?:[];
        $counts=['verified'=>0,'processed'=>0,'failed'=>0,'unresolved'=>0];
        foreach($rows as $row){$status=strtoupper((string)$row['processing_status']);if($status==='PROCESSED')$counts['processed']++;elseif($status==='FAILED')$counts['failed']++;elseif($status==='VERIFIED')$counts['verified']++;else $counts['unresolved']++;}
        return ['provider'=>$provider,'counts'=>$counts,'events'=>$rows,'retry_performed'=>false,'external_execution_performed'=>false];
    }
}
