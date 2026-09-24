<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;

/** Uses the existing idempotency table to claim webhook event IDs exactly once. */
final class EtsyWebhookDeduplicator {
    /** @return array<string,mixed>|WP_Error */
    public function claim(string $eventId): array|WP_Error {
        global $wpdb;
        $eventId=trim($eventId);
        if($eventId===''||strlen($eventId)>191)return new WP_Error('digiforge_etsy_webhook_event_id','Invalid Etsy webhook event id.',['status'=>409]);
        $key='etsy_webhook:'.hash('sha256',$eventId);
        $table=$wpdb->prefix.'digiforge_idempotency';
        $now=current_time('mysql',true);
        $inserted=$wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (operation_key,operation_type,status,response_hash,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s)",
            $key,'ETSY_WEBHOOK','RECEIVED','',$now,$now
        ));
        if($inserted===false)return new WP_Error('digiforge_etsy_webhook_idempotency','Webhook deduplication storage failed.',['status'=>503]);
        if($inserted===0){
            $row=$wpdb->get_row($wpdb->prepare("SELECT status FROM {$table} WHERE operation_key=%s LIMIT 1",$key),ARRAY_A);
            if(is_array($row)&&strtoupper((string)($row['status']??''))==='FAILED'){
                $updated=$wpdb->query($wpdb->prepare("UPDATE {$table} SET status=%s,updated_at=%s WHERE operation_key=%s AND status=%s",'RECEIVED',$now,$key,'FAILED'));
                if($updated===1)return ['state'=>'ETSY_WEBHOOK_RETRY_CLAIMED','event_id'=>$eventId,'process_permitted'=>true,'external_execution_performed'=>false];
            }
            return ['state'=>'ETSY_WEBHOOK_DUPLICATE','event_id'=>$eventId,'process_permitted'=>false,'external_execution_performed'=>false];
        }
        return ['state'=>'ETSY_WEBHOOK_CLAIMED','event_id'=>$eventId,'process_permitted'=>true,'external_execution_performed'=>false];
    }

    public function markFailed(string $eventId): bool
    {
        global $wpdb;
        $key='etsy_webhook:'.hash('sha256',trim($eventId));
        return $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}digiforge_idempotency SET status=%s,updated_at=%s WHERE operation_key=%s AND status=%s",'FAILED',current_time('mysql',true),$key,'RECEIVED'))===1;
    }

    public function markProcessed(string $eventId,string $responseHash=''): bool
    {
        global $wpdb;
        $key='etsy_webhook:'.hash('sha256',trim($eventId));
        return $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}digiforge_idempotency SET status=%s,response_hash=%s,updated_at=%s WHERE operation_key=%s AND status=%s",'PROCESSED',$responseHash,current_time('mysql',true),$key,'RECEIVED'))===1;
    }
}
