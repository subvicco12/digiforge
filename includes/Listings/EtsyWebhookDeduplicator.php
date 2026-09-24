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
        $inserted=$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$table} (idempotency_key,created_at) VALUES (%s,%s)",$key,current_time('mysql',true)));
        if($inserted!==1)return ['state'=>'ETSY_WEBHOOK_DUPLICATE','event_id'=>$eventId,'process_permitted'=>false,'external_execution_performed'=>false];
        return ['state'=>'ETSY_WEBHOOK_CLAIMED','event_id'=>$eventId,'process_permitted'=>true,'external_execution_performed'=>false];
    }
}
