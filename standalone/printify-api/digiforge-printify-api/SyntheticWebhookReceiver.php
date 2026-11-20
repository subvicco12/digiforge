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
        'order:created'=>'order', 'order:updated'=>'order', 'order:sent-to-production'=>'order',
        'product:created'=>'product', 'product:updated'=>'product', 'product:publish:started'=>'product',
        'order:shipment:created'=>'order', 'order:shipment:delivered'=>'order',
        'shop:disconnected'=>'shop','product:deleted'=>'product','order:line-item:canceled'=>'order',
        'personalization-preview-task:processed'=>'personalization-preview-task','support-request:updated'=>'support-request',
    ];
    private const ORDER_STATES = ['pending','on-hold','sending-to-production','in-production','canceled',
        'fulfilled','partially-fulfilled','payment-not-received','has-issues','cost-calculation','unfulfillable',
        'sending_to_production_delegate','sending_to_production_delegate_sync','source-check-failed'];

    private const PUBLISH_FIELDS = ['title','variants','description','tags','images','key_features','shipping_template'];

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

    /** Serialize for an isolated fixture receiver. This does not open a socket. */
    public static function wire(array $delivery): string
    {
        $body=$delivery['body'];
        return "POST ".self::PATH." HTTP/1.1\r\nHost: fixture.invalid\r\nContent-Type: application/json\r\n"
            ."X-Pfy-Signature: ".$delivery['headers']['x-pfy-signature']."\r\n"
            ."Content-Length: ".strlen($body)."\r\n\r\n".$body;
    }

    /** Parse a single bounded HTTP fixture message. No route or listener is registered. */
    public static function receiveWire(string $shop,string $wire,array $seen=[]): array
    {
        if (strlen($wire)>self::MAX_BYTES+1024 || !str_contains($wire,"\r\n\r\n")) {
            return self::result(400,'EVENT_HTTP_FRAMING');
        }
        [$head,$body]=explode("\r\n\r\n",$wire,2);
        $lines=explode("\r\n",$head);
        if (array_shift($lines)!=='POST '.self::PATH.' HTTP/1.1' || count($lines)!==4 || strlen($head)>1024) {
            return self::result(400,'EVENT_HTTP_FRAMING');
        }
        $headers=[];
        foreach ($lines as $line) {
            if (preg_match('/^([a-z-]+): ([^\x00-\x1f\x7f]+)$/Di',$line,$match)!==1
                || isset($headers[strtolower($match[1])])) {
                return self::result(400,'EVENT_HTTP_FRAMING');
            }
            $headers[strtolower($match[1])]=$match[2];
        }
        if (!self::keys($headers,['host','content-type','x-pfy-signature','content-length'])
            || $headers['host']!=='fixture.invalid' || $headers['content-length']!==(string)strlen($body)) {
            return self::result(400,'EVENT_HTTP_FRAMING');
        }
        unset($headers['host'],$headers['content-length']);
        return self::receive($shop,['method'=>'POST','path'=>self::PATH,'headers'=>$headers,'body'=>$body],$seen);
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
        if (!is_array($event) || !self::envelope($event) || !self::exactJsonMembers($raw, self::expectedMembers($event))
            || preg_match('/^[1-9][0-9]{0,18}$/D', $shop) !== 1
            || ($event['type']!=='product:publish:started'||($event['resource']['data']['action']??null)!=='delete')
                && (string)($event['resource']['data']['shop_id'] ?? $event['resource']['id']) !== $shop) {
            return self::result(422, 'EVENT_CONTRACT_OR_SCOPE');
        }

        $timestamp = $event['created_at'];
        if (!self::timestamp($timestamp)) {
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
            'resource_id'=>(string)$event['resource']['id'], 'resource_type'=>$event['resource']['type'],
            'provider_shop_id'=>$shop, 'created_at'=>$timestamp, 'fixture_signature_verified'=>true,
        ];
        $data = $event['resource']['data'];
        return $observation + match ($event['type']) {
            'order:updated' => ['resource_status'=>$data['status']],
            'order:line-item:canceled' => ['canceled_line_id'=>$data['line_item_id']],
            'personalization-preview-task:processed' => ['preview_status'=>$data['status'],'preview_mockups'=>$data['mockups']],
            'support-request:updated' => ['support_order_id'=>$data['order_id'],'support_type'=>$data['type'],
                'support_status'=>$data['status'],'support_outcome'=>$data['outcome']],
            'product:publish:started' => [
                'publish_action'=>$data['action'], 'out_of_stock_publishing'=>$data['out_of_stock_publishing']??null,
            ],
            'order:shipment:created' => [
                'shipment_at'=>self::utcTimestamp($data['shipped_at']), 'tracking_sha256'=>hash('sha256',$data['carrier']['tracking_number']),
                'line_item_ids'=>$data['line_item_ids'],
            ],
            'order:shipment:delivered' => [
                'shipment_at'=>self::utcTimestamp($data['delivered_at']), 'tracking_sha256'=>hash('sha256',$data['carrier']['tracking_number']),
            ],
            default => [],
        };
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
        if ($event['type']==='shop:disconnected') {
            return self::keys($resource,['id','type','data']) && $resource['type']==='shop'
                && is_int($resource['id']) && $resource['id']>0 && $resource['data']===null;
        }
        if ($event['type']==='product:publish:started' && ($resource['data']??null)===['action'=>'delete']) {
            return self::keys($resource,['id','type','data']) && $resource['type']==='product'
                && self::identifier($resource['id']);
        }
        if (!self::keys($resource, ['id','type','data']) || !self::identifier($resource['id'])
            || $resource['type']!==self::EVENT_TYPES[$event['type']] || !is_array($resource['data'])
            || !is_int($resource['data']['shop_id']??null) || $resource['data']['shop_id']<1) {
            return false;
        }
        $data=$resource['data'];
        return match ($event['type']) {
            'order:updated' => self::keys($data,['shop_id','status']) && in_array($data['status'],self::ORDER_STATES,true),
            'product:publish:started' => self::publishDetails($data),
            'order:line-item:canceled' => self::keys($data,['shop_id','line_item_id','status','status_details'])
                && self::identifier($data['line_item_id']) && $data['status']==='canceled'
                && ($data['status_details']===null || self::identifier($data['status_details'])),
            'personalization-preview-task:processed' => self::previewDetails($data,$resource['id']),
            'support-request:updated' => self::keys($data,['shop_id','order_id','type','status','status_details','outcome'])
                && self::identifier($data['order_id']) && ($data['type']===null || self::identifier($data['type']))
                && in_array($data['status'],['pending','processing','in_progress','resolved'],true)
                && in_array($data['outcome'],['unresolved','positive','negative'],true)
                && ($data['status']==='resolved' || $data['outcome']==='unresolved')
                && ($data['status_details']===null || is_string($data['status_details']) && strlen($data['status_details'])<=512),
            'order:shipment:created' => self::shipmentDetails($data,'shipped_at'),
            'order:shipment:delivered' => self::shipmentDetails($data,'delivered_at'),
            default => self::keys($data,['shop_id']),
        };
    }

    private static function previewDetails(array $data,string $task): bool
    {
        if (!self::keys($data,['shop_id','task_id','status','preview_count','mockups']) || $data['task_id']!==$task
            || !in_array($data['status'],['completed','failed','corrupt'],true) || !is_array($data['mockups'])
            || !array_is_list($data['mockups']) || count($data['mockups'])>20
            || $data['preview_count']!==count($data['mockups'])) {
            return false;
        }
        foreach ($data['mockups'] as $mockup) {
            if (!is_array($mockup)||!self::keys($mockup,['variant_id','mockup_id','src'])
                || !is_int($mockup['variant_id'])||$mockup['variant_id']<1||$mockup['mockup_id']!=='Fixture'
                || $mockup['src']!=='https://images.example.invalid/'.$task.'-'.$mockup['variant_id'].'.svg') {
                return false;
            }
        }
        return ($data['status']==='completed')===($data['mockups']!==[]);
    }

    private static function publishDetails(array $data): bool
    {
        if (!self::keys($data,['shop_id','publish_details','action','out_of_stock_publishing'])
            || !in_array($data['action'],['create','update'],true) || $data['out_of_stock_publishing']!==0
            || !is_array($data['publish_details']) || !self::keys($data['publish_details'],self::PUBLISH_FIELDS)) {
            return false;
        }
        foreach ($data['publish_details'] as $selection) {
            if (!is_bool($selection)) {
                return false;
            }
        }
        return true;
    }

    private static function shipmentDetails(array $data, string $dateKey): bool
    {
        $keys=['shop_id',$dateKey,'carrier','skus'];
        if ($dateKey==='shipped_at') {
            $keys[]='line_item_ids';
        }
        if (!self::keys($data,$keys) || !self::timestamp($data[$dateKey]) || !self::identifiers($data['skus'])
            || ($dateKey==='shipped_at' && !self::identifiers($data['line_item_ids']))) {
            return false;
        }
        $carrier=$data['carrier'];
        if (!is_array($carrier) || !self::keys($carrier,['code','tracking_number','tracking_url'])
            || !self::identifier($carrier['code']) || !self::identifier($carrier['tracking_number'])
            || !is_string($carrier['tracking_url']) || strlen($carrier['tracking_url'])>2048
            || filter_var($carrier['tracking_url'],FILTER_VALIDATE_URL)===false) {
            return false;
        }
        $url=parse_url($carrier['tracking_url']);
        return is_array($url) && ($url['scheme']??null)==='https' && isset($url['host'])
            && !isset($url['user']) && !isset($url['pass']);
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$value)===1;
    }

    private static function identifiers(mixed $items): bool
    {
        if (!is_array($items) || !array_is_list($items) || $items===[] || count($items)>100) {
            return false;
        }
        foreach ($items as $item) {
            if (!self::identifier($item)) {
                return false;
            }
        }
        return true;
    }

    private static function timestamp(mixed $value): bool
    {
        if (!is_string($value)
            || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}[+-](?:0[0-9]|1[0-4]):[0-5][0-9]$/D',$value)!==1) {
            return false;
        }
        $time=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:sP',$value);
        return $time!==false && $time->format('Y-m-d H:i:sP')===$value;
    }

    private static function utcTimestamp(string $value): string
    {
        return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function expectedMembers(array $event): int
    {
        $data=$event['resource']['data'] ?? [];
        return 7 + count($data) + match ($event['type']) {
            'product:publish:started' => count($data['publish_details']??[]),
            'personalization-preview-task:processed' => 3*count($data['mockups']),
            'order:shipment:created', 'order:shipment:delivered' => count($data['carrier']),
            default => 0,
        };
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
