<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;

/** Verification-first webhook intake; performs no order mutation or outbound action. */
final class EtsyWebhookIntake {
    public function __construct(private EtsyWebhookDeduplicator $dedup) {}
    /** @return array<string,mixed>|WP_Error */
    public function accept(string $body,array $headers,string $secret,int $now): array|WP_Error {
        $verified=EtsyWebhookVerifier::verify($body,$headers,$secret,$now);
        if($verified instanceof WP_Error)return $verified;
        $claim=$this->dedup->claim((string)$verified['event_id']);
        if($claim instanceof WP_Error)return $claim;
        if(($claim['process_permitted']??false)!==true)return $claim;
        return ['state'=>'ETSY_WEBHOOK_ACCEPTED','event_id'=>$verified['event_id'],'event_type'=>$verified['event_type'],'payload'=>$verified['payload'],'order_mutation_performed'=>false,'external_execution_performed'=>false];
    }
}
