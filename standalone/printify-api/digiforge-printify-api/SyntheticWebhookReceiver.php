<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!\defined('ABSPATH')) {
    exit;
}

/** Pure fixture decoder. The caller owns durable acceptance and no WordPress route is registered. */
final class SyntheticWebhookReceiver
{
    public const PATH = '/synthetic/printify/webhook';
    private const MAX_BYTES = 16384;
    // Public fixture material. It is intentionally unsuitable for authenticating provider traffic.
    private const FIXTURE_SIGNING_KEY = 'digiforge-synthetic-webhook-envelope-fixture-v1';
    private const EVENT_TYPES = [
        'order:created'=>'order', 'order:updated'=>'order',
        'product:created'=>'product', 'product:updated'=>'product',
    ];
    private const ORDER_STATES = ['pending','on-hold','sending-to-production','in-production','canceled',
        'fulfilled','partially-fulfilled','payment-not-received','has-issues','cost-calculation','unfulfillable',
        'sending_to_production_delegate','sending_to_production_delegate_sync','source-check-failed'];

    /** Create no request outside this process. Only the supported scalar envelope can be serialized. */
    public static function delivery(array $event): array
    {
        if (!self::envelope($event)) {
            throw new \InvalidArgumentException('Unsupported synthetic event envelope.');
        }
        return self::deliveryRaw(json_encode($event, JSON_THROW_ON_ERROR, 12));
    }

    /** Sign exact synthetic bytes, including malformed test inputs, entirely in process. */
    public static function deliveryRaw(string $body): array
    {
        if (strlen($body)>self::MAX_BYTES) {
            throw new \InvalidArgumentException('Synthetic request exceeds the fixture bound.');
        }
        return ['method'=>'POST','path'=>self::PATH,
            'headers'=>['content-type'=>'application/json','x-pfy-signature'=>self::signature($body)], 'body'=>$body];
    }

    /** @param array<string,string> $seen Lowercase event ID to digest for this exact workspace. */
    public static function receive(string $shop, array $request, array $seen = []): array
    {
        if (!self::keys($request, ['method','path','headers','body'])
            || $request['method'] !== 'POST' || $request['path'] !== self::PATH) {
            return self::result(404, 'EVENT_ROUTE_UNAVAILABLE');
        }
        $headers = is_array($request['headers']) ? array_change_key_case($request['headers'], CASE_LOWER) : [];
        if (!self::keys($headers,['content-type','x-pfy-signature']) || count($request['headers'])!==2
            || $headers['content-type']!=='application/json') {
            return self::result(415, 'EVENT_MEDIA_TYPE');
        }
        $raw = $request['body'];
        if (!is_string($raw) || $raw === '' || strlen($raw) > self::MAX_BYTES) {
            return self::result(413, 'EVENT_SIZE');
        }
        $signature = $headers['x-pfy-signature'];
        if (!is_string($signature) || preg_match('/^sha256=[a-f0-9]{64}$/D',$signature)!==1
            || !hash_equals(self::signature($raw),$signature)) {
            return self::result(401, 'EVENT_FIXTURE_SIGNATURE');
        }
        try {
            $event = json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::result(422, 'EVENT_MALFORMED');
        }
        if (!is_array($event) || !self::envelope($event) || !self::exactJsonMembers($raw, 7 + count($event['resource']['data']))
            || preg_match('/^[1-9][0-9]{0,18}$/D', $shop) !== 1
            || (string)$event['resource']['data']['shop_id'] !== $shop) {
            return self::result(422, 'EVENT_CONTRACT_OR_SCOPE');
        }

        $timestamp = $event['created_at'];
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}[+-](?:0[0-9]|1[0-4]):[0-5][0-9]$/D', $timestamp) !== 1) {
            return self::result(422, 'EVENT_TIMESTAMP');
        }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:sP', $timestamp);
        if (!$time || $time->format('Y-m-d H:i:sP') !== $timestamp) {
            return self::result(422, 'EVENT_TIMESTAMP');
        }

        $id = strtolower($event['id']);
        $digest = hash('sha256', $raw);
        if (array_key_exists($id, $seen)) {
            $previous = $seen[$id];
            if (!is_string($previous) || preg_match('/^[a-f0-9]{64}$/D', $previous) !== 1) {
                return self::result(409, 'EVENT_REPLAY_UNVERIFIED');
            }
            return self::result(409, hash_equals($previous, $digest) ? 'DUPLICATE_EVENT' : 'EVENT_ID_CONFLICT');
        }

        $observation = self::result(200, 'NONE') + [
            'event_id'=>$id, 'event_digest'=>$digest, 'type'=>$event['type'],
            'resource_id'=>$event['resource']['id'], 'resource_type'=>$event['resource']['type'],
            'provider_shop_id'=>$shop, 'created_at'=>$timestamp, 'fixture_signature_verified'=>true,
        ];
        return $event['type']==='order:updated'
            ? $observation + ['resource_status'=>$event['resource']['data']['status']]
            : $observation;
    }

    private static function envelope(array $event): bool
    {
        if (!self::keys($event, ['id','type','created_at','resource'])
            || !is_string($event['id'])
            || preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/Di',$event['id']) !== 1
            || !is_string($event['type']) || !isset(self::EVENT_TYPES[$event['type']])
            || !is_string($event['created_at']) || strlen($event['created_at']) !== 25
            || !is_array($event['resource'])) {
            return false;
        }
        $resource = $event['resource'];
        $updatedOrder = $event['type']==='order:updated';
        return self::keys($resource, ['id','type','data']) && is_string($resource['id'])
            && preg_match('/^[A-Za-z0-9_-]{1,160}$/D', $resource['id']) === 1
            && $resource['type']===self::EVENT_TYPES[$event['type']] && is_array($resource['data'])
            && self::keys($resource['data'], $updatedOrder ? ['shop_id','status'] : ['shop_id'])
            && is_int($resource['data']['shop_id']) && $resource['data']['shop_id'] > 0
            && (!$updatedOrder || in_array($resource['data']['status'],self::ORDER_STATES,true));
    }

    /** PHP's decoded object hides duplicate members; the accepted fixed envelope has a known member count. */
    private static function exactJsonMembers(string $raw, int $expected): bool
    {
        if (preg_match_all('~"(?:[^"\\\\]++|\\\\.)*"\\s*(:)?~s', $raw, $tokens) === false) {
            return false;
        }
        return count(array_filter($tokens[1], static fn(string $separator): bool => $separator === ':')) === $expected;
    }

    private static function signature(string $raw): string
    {
        return 'sha256='.hash_hmac('sha256',$raw,self::FIXTURE_SIGNING_KEY);
    }

    private static function keys(array $value, array $expected): bool
    {
        return count($value) === count($expected) && array_diff_key(array_flip($expected), $value) === [];
    }

    private static function result(int $status, string $reason): array
    {
        return ['status'=>$status, 'state'=>$status===200?'SYNTHETIC_OBSERVED':'DENIED', 'reason'=>$reason,
            'synthetic'=>true, 'event_authenticity_verified'=>false, 'business_transition_authorized'=>false,
            'provider_execution_authorized'=>false, 'retry_permitted'=>false];
    }
}
