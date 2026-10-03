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
        $evidence=new WebhookEvidenceRepository();
        $record=$evidence->recordVerified($verified,$body,$headers);if($record instanceof WP_Error)return $record;
        $claim=$this->dedup->claim((string)$verified['event_id']);
        if($claim instanceof WP_Error)return $claim;
        if(($claim['process_permitted']??false)!==true)return $claim;
        $result=(new EtsyOrderWebhookLifecycle(new \DigiForge\Orders\Repository()))->apply($verified);
        if($result instanceof WP_Error){$dedupFailed=$this->dedup->markFailed((string)$verified['event_id']);$evidenceFailed=$evidence->markFailed((string)$verified['event_id']);if(!$dedupFailed||!$evidenceFailed)return new WP_Error('digiforge_etsy_webhook_failure_evidence_unavailable','Webhook processing failed and durable failure evidence could not be completed.',['status'=>503,'reconciliation_required'=>true,'external_execution_performed'=>false]);return $result;}
        $resultHash=hash('sha256',wp_json_encode($result));
        $dedupProcessed=$this->dedup->markProcessed((string)$verified['event_id'],$resultHash);$evidenceProcessed=$evidence->markProcessed((string)$verified['event_id'],$resultHash);if(!$dedupProcessed||!$evidenceProcessed)return new WP_Error('digiforge_etsy_webhook_completion_evidence_unavailable','Webhook lifecycle completed but durable completion evidence is uncertain.',['status'=>503,'reconciliation_required'=>true,'external_execution_performed'=>false]);
        return $result+['webhook_verified'=>true,'external_execution_performed'=>false];
    }
}
