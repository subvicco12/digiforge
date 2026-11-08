<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Offline-verifiable ingress boundary; does not register routes or apply business transitions. */
final class PrintifyWebhookEvidence
{
    private const TOPICS = ['shop:disconnected','product:created','product:updated','product:deleted','product:publish:started','order:created','order:updated','order:line-item:canceled','order:shipment:created','order:shipment:delivered','order:sent-to-production','personalization-preview-task:processed','support-request:updated'];

    public static function verify(string $raw, string $signature, string $secret, string $shop, int $now): array
    {
        if (strlen($raw) > 1048576 || $raw === '' || strlen($secret) < 32 || !preg_match('/^[1-9][0-9]{0,18}$/D', $shop) || !preg_match('/^sha256=[a-f0-9]{64}$/D', $signature)) { throw new \InvalidArgumentException('Invalid webhook envelope.'); }
        if (!hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $signature)) { throw new \InvalidArgumentException('Webhook signature mismatch.'); }
        try { $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); } catch (\JsonException $error) { throw new \InvalidArgumentException('Malformed signed webhook.', 0, $error); }
        if (!is_array($event) || !is_string($event['id'] ?? null) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $event['id']) || !in_array($event['type'] ?? null, self::TOPICS, true)) { throw new \InvalidArgumentException('Unsupported webhook identity or topic.'); }
        $created = $event['created_at'] ?? null;
        if (!is_string($created)) { throw new \InvalidArgumentException('Missing event timestamp.'); }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:sP', $created);
        if (!$time || $time->format('Y-m-d H:i:sP') !== $created || $time->getTimestamp() > $now + 60 || $time->getTimestamp() < $now - 86400) { throw new \InvalidArgumentException('Webhook outside local evidence freshness window.'); }
        $resource = $event['resource'] ?? null;
        if (!is_array($resource) || !is_string($resource['id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,160}$/D', $resource['id'])) { throw new \InvalidArgumentException('Invalid resource identity.'); }
        $expectedType = explode(':', $event['type'], 2)[0];
        if (($resource['type'] ?? null) !== $expectedType) { throw new \InvalidArgumentException('Resource topic mismatch.'); }
        $data = $resource['data'] ?? null;
        if ($data !== null && !is_array($data)) { throw new \InvalidArgumentException('Invalid resource data.'); }
        if (isset($data['shop_id']) && (string)$data['shop_id'] !== $shop) { throw new \InvalidArgumentException('Webhook shop mismatch.'); }
        if ($expectedType === 'shop' && $resource['id'] !== $shop) { throw new \InvalidArgumentException('Webhook shop resource mismatch.'); }
        // Shop-less events require a unique, server-provisioned per-shop secret at intake.
        // The caller must durably claim this dedupe key before acknowledging or using evidence.
        return ['event_id'=>$event['id'],'topic'=>$event['type'],'provider_shop_id'=>$shop,'resource_id'=>$resource['id'],'created_at'=>$created,'payload_sha256'=>hash('sha256',$raw),'dedupe_key'=>hash('sha256',$shop . "\0" . strtolower($event['id'])),'durable_replay_claim_required'=>true,'business_transition_authorized'=>false];
    }

    public static function replayDisposition(array $existing, array $incoming): string
    {
        if (($existing['dedupe_key'] ?? null) !== ($incoming['dedupe_key'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', (string)($existing['payload_sha256'] ?? '')) || !preg_match('/^[a-f0-9]{64}$/D', (string)($incoming['payload_sha256'] ?? ''))) { throw new \InvalidArgumentException('Invalid replay evidence.'); }
        return hash_equals($existing['payload_sha256'], $incoming['payload_sha256']) ? 'DUPLICATE_NO_TRANSITION' : 'CONFLICT_REJECT';
    }
}
