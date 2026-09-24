<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;

/** Pure Etsy webhook authenticity + replay-window verifier. */
final class EtsyWebhookVerifier {
    private const MAX_BODY_BYTES=262144;
    private const MAX_SKEW_SECONDS=300;
    /** @return array<string,mixed>|WP_Error */
    public static function verify(string $body,array $headers,string $secret,int $now): array|WP_Error {
        if ($secret==='' || strlen($secret)>4096) return self::error('secret','Webhook secret is unavailable.');
        if ($body==='' || strlen($body)>self::MAX_BODY_BYTES) return self::error('body','Webhook body is empty or too large.');
        $id=self::header($headers,'webhook-id');
        $timestamp=self::header($headers,'webhook-timestamp');
        $signature=self::header($headers,'webhook-signature');
        if ($id==='' || strlen($id)>191 || !ctype_digit($timestamp)) return self::error('headers','Required webhook identity headers are invalid.');
        $ts=(int)$timestamp;
        if (abs($now-$ts)>self::MAX_SKEW_SECONDS) return self::error('stale','Webhook timestamp is outside the replay window.');
        $expected=base64_encode(hash_hmac('sha256',$id.'.'.$timestamp.'.'.$body,$secret,true));
        $valid=false;
        foreach (preg_split('/\\s+/',trim($signature))?:[] as $candidate) {
            $parts=explode(',',$candidate,2);
            $value=count($parts)===2?$parts[1]:$parts[0];
            if ($value!=='' && hash_equals($expected,$value)) {$valid=true;break;}
        }
        if (!$valid) return self::error('signature','Webhook signature verification failed.');
        $payload=json_decode($body,true);
        if (!is_array($payload)) return self::error('json','Webhook body is not valid JSON.');
        $event=trim((string)($payload['event_type']??$payload['type']??''));
        if (!in_array($event,['order.paid','order.canceled','order.shipped','order.delivered'],true)) return self::error('event','Webhook event type is not supported.');
        return ['state'=>'ETSY_WEBHOOK_VERIFIED','event_id'=>$id,'event_type'=>$event,'payload'=>$payload,'raw_body_returned'=>false,'external_execution_performed'=>false];
    }
    private static function header(array $headers,string $name): string {
        foreach($headers as $key=>$value) if(strtolower((string)$key)===$name){if(is_array($value))$value=reset($value);return trim((string)$value);}
        return '';
    }
    private static function error(string $code,string $message): WP_Error {return new WP_Error('digiforge_etsy_webhook_'.$code,$message,['status'=>401]);}
}
