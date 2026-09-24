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
        $result=(new EtsyOrderWebhookLifecycle(new \DigiForge\Orders\Repository()))->apply($verified);
        if($result instanceof WP_Error){$this->dedup->markFailed((string)$verified['event_id']);return $result;}
        $this->dedup->markProcessed((string)$verified['event_id'],hash('sha256',wp_json_encode($result)));
        return $result+['webhook_verified'=>true,'external_execution_performed'=>false];
    }
}
