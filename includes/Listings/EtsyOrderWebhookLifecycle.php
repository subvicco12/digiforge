<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use DigiForge\Orders\Repository;
use WP_Error;

/**
 * Maps an already-authenticated/deduplicated Etsy webhook into the local
 * order boundary. It never authorizes fulfillment or provider production.
 */
final class EtsyOrderWebhookLifecycle
{
    public function __construct(private Repository $orders) {}

    /** @return array<string,mixed>|WP_Error */
    public function apply(array $event): array|WP_Error
    {
        $type=strtoupper(trim((string)($event['event_type']??'')));
        $payload=is_array($event['payload']??null)?$event['payload']:[];
        $eventId=trim((string)($event['event_id']??''));
        if($eventId===''||strlen($eventId)>191)return self::error('event_id','Verified Etsy event identity is required.');

        $orderRef=$this->reference($payload,['order_id','orderId','id']);
        $shopRef=$this->reference($payload,['shop_id','shopId']);
        if($orderRef===''||$shopRef==='')return self::error('identity','Etsy webhook must contain bounded order and shop identities.');

        if($type==='ORDER.PAID'){
            $existing=$this->orders->findByExternalReference($orderRef,$shopRef);
            if($existing!==null)return ['state'=>'ETSY_ORDER_ALREADY_RECEIVED','event_id'=>$eventId,'order_id'=>(int)$existing['id'],'fulfillment_authorized'=>false,'external_execution_performed'=>false];
            $order=$this->orders->createOrder([
                'channel'=>'etsy','environment'=>'production','external_order_reference'=>$orderRef,
                'shop_reference'=>$shopRef,'buyer_reference'=>$this->reference($payload,['buyer_id','buyerId']),
                'currency'=>$this->currency($payload),'subtotal_amount'=>$this->amount($payload,'subtotal'),
                'shipping_amount'=>$this->amount($payload,'shipping'),'tax_amount'=>$this->amount($payload,'tax'),
                'total_amount'=>$this->amount($payload,'total'),'personalization_required'=>$this->personalized($payload),
                'metadata'=>['source'=>'etsy_webhook','event_id'=>$eventId],
            ],'etsy_webhook:'.$eventId);
            if($order instanceof WP_Error)return $order;
            return ['state'=>'ETSY_ORDER_RECEIVED','event_id'=>$eventId,'order_id'=>(int)$order['id'],'fulfillment_authorized'=>false,'external_execution_performed'=>false];
        }

        $existing=$this->orders->findByExternalReference($orderRef,$shopRef);
        if($existing===null)return self::error('order_not_found','Verified Etsy event references no local order.');
        if($type==='ORDER.CANCELED'){
            $state=(string)$existing['state'];
            if(in_array($state,['RECEIVED','VALIDATED','REVIEW_REQUIRED','ON_HOLD'],true)){
                $result=$this->orders->transition('order',(int)$existing['id'],'REJECTED');
            } elseif($state==='APPROVED'){
                $result=$this->orders->transition('order',(int)$existing['id'],'ON_HOLD');
                if($result instanceof WP_Error)return $result;
            }
            return ['state'=>'ETSY_ORDER_CANCELLATION_RECORDED','event_id'=>$eventId,'order_id'=>(int)$existing['id'],'fulfillment_authorized'=>false,'external_execution_performed'=>false];
        }

        if(in_array($type,['ORDER.SHIPPED','ORDER.DELIVERED'],true)){
            return ['state'=>'ETSY_ORDER_EVENT_RECORDED','event_id'=>$eventId,'order_id'=>(int)$existing['id'],'event_type'=>$type,'fulfillment_authorized'=>false,'external_execution_performed'=>false];
        }
        return self::error('event','Unsupported verified Etsy lifecycle event.');
    }

    private function reference(array $p,array $keys): string {foreach($keys as $k){$v=$p[$k]??'';if(is_scalar($v)){$v=trim((string)$v);if($v!==''&&strlen($v)<=191)return sanitize_text_field($v);}}return '';}
    private function amount(array $p,string $key): float { $v=$p[$key.'_amount']??($p[$key]??0); return is_numeric($v)?max(0,min(999999999.99,(float)$v)):0.0; }
    private function currency(array $p): string { $v=strtoupper(trim((string)($p['currency']??'USD')));return preg_match('/^[A-Z]{3}$/',$v)?$v:'USD';}
    private function personalized(array $p): bool {return !empty($p['personalization_required'])||!empty($p['personalization']);}
    private static function error(string $c,string $m): WP_Error{return new WP_Error('digiforge_etsy_order_webhook_'.$c,$m,['status'=>409]);}
}
