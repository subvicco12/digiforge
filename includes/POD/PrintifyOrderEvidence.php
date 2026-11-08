<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Strict existing-product order preparation and privacy-minimizing readback. */
final class PrintifyOrderEvidence
{
    private const STATES = ['pending','on-hold','sending-to-production','in-production','canceled','fulfilled','partially-fulfilled','payment-not-received','has-issues','cost-calculation','unfulfillable','sending_to_production_delegate','sending_to_production_delegate_sync','source-check-failed'];

    public static function validate(array $body): void
    {
        self::text($body['external_id'] ?? null, 128);
        $allowed = ['external_id','label','line_items','shipping_method','is_printify_express','is_economy_shipping','send_shipping_notification','address_to'];
        if (array_diff(array_keys($body), $allowed)) { throw new \InvalidArgumentException('Unsupported order fields.'); }
        if (!is_array($body['line_items'] ?? null) || !array_is_list($body['line_items']) || count($body['line_items']) < 1 || count($body['line_items']) > 100) { throw new \InvalidArgumentException('Bounded order lines required.'); }
        foreach ($body['line_items'] as $line) {
            if (!is_array($line) || array_diff(array_keys($line), ['product_id','variant_id','quantity','external_id'])) { throw new \InvalidArgumentException('Only existing mapped products supported.'); }
            if (!is_string($line['product_id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,160}$/D', $line['product_id'])) { throw new \InvalidArgumentException('Existing product required.'); }
            foreach (['variant_id','quantity'] as $field) {
                if (!is_int($line[$field] ?? null) || $line[$field] < 1 || ($field === 'quantity' && $line[$field] > 1000)) { throw new \InvalidArgumentException('Invalid variant or quantity.'); }
            }
            if (isset($line['external_id'])) { self::text($line['external_id'], 128); }
        }
        if (!is_int($body['shipping_method'] ?? null) || !in_array($body['shipping_method'], [1,2,3,4], true)) { throw new \InvalidArgumentException('Explicit supported shipping method required.'); }
        foreach (['is_printify_express','is_economy_shipping','send_shipping_notification'] as $field) {
            if (!is_bool($body[$field] ?? null)) { throw new \InvalidArgumentException('Explicit shipping and notification flags required.'); }
        }
        if ($body['is_printify_express'] !== ($body['shipping_method'] === 3) || $body['is_economy_shipping'] !== ($body['shipping_method'] === 4)) { throw new \InvalidArgumentException('Conflicting shipping selections.'); }
        $address = $body['address_to'] ?? null;
        if (!is_array($address) || array_diff(array_keys($address), ['first_name','last_name','email','phone','country','region','address1','address2','city','zip'])) { throw new \InvalidArgumentException('Invalid address fields.'); }
        foreach (['first_name','last_name','email','phone','country','region','address1','address2','city','zip'] as $field) {
            if (!array_key_exists($field, $address) || !is_string($address[$field]) || strlen($address[$field]) > 255 || preg_match('/[\x00-\x1F\x7F]/', $address[$field])) { throw new \InvalidArgumentException('Bounded explicit address fields required.'); }
        }
        foreach (['first_name','last_name','address1','city','zip'] as $field) { self::text($address[$field], 255); }
        if (!preg_match('/^[A-Z]{2}$/D', $address['country']) || !filter_var($address['email'], FILTER_VALIDATE_EMAIL)) { throw new \InvalidArgumentException('Invalid destination country or email.'); }
    }

    public static function project(array $order, string $expectedId): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,160}$/D', $expectedId) || ($order['id'] ?? null) !== $expectedId || !in_array($order['status'] ?? null, self::STATES, true)) { throw new \InvalidArgumentException('Order identity or status mismatch.'); }
        if (!is_array($order['line_items'] ?? null) || !array_is_list($order['line_items']) || count($order['line_items']) > 100) { throw new \InvalidArgumentException('Malformed order lines.'); }
        $lines = [];
        foreach ($order['line_items'] as $line) {
            if (!is_array($line) || !is_string($line['product_id'] ?? null) || !is_int($line['variant_id'] ?? null) || $line['variant_id'] < 1 || !is_int($line['quantity'] ?? null) || $line['quantity'] < 1) { throw new \InvalidArgumentException('Malformed order line evidence.'); }
            $lines[] = array_intersect_key($line, array_flip(['product_id','variant_id','quantity']));
        }
        $shipments = [];
        if (!is_array($order['shipments'] ?? []) || !array_is_list($order['shipments'] ?? []) || count($order['shipments'] ?? []) > 100) { throw new \InvalidArgumentException('Malformed shipments.'); }
        foreach ($order['shipments'] ?? [] as $shipment) {
            if (!is_array($shipment)) { throw new \InvalidArgumentException('Malformed shipment.'); }
            self::text($shipment['carrier'] ?? null, 100);
            self::text($shipment['number'] ?? null, 255);
            $url = $shipment['url'] ?? null;
            if (!is_string($url) || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http','https'], true) || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) { throw new \InvalidArgumentException('Malformed tracking reference.'); }
            $delivered = $shipment['delivered_at'] ?? null;
            if ($delivered !== null && (!is_string($delivered) || strlen($delivered) > 40 || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:?\d{2})?$/D', $delivered) || strtotime($delivered) === false)) { throw new \InvalidArgumentException('Invalid delivery timestamp.'); }
            $shipments[] = ['carrier'=>$shipment['carrier'],'tracking_hash'=>hash('sha256',$shipment['number']),'url_observed'=>true,'delivered_at'=>$delivered];
        }
        return ['id'=>$expectedId,'status'=>$order['status'],'line_items'=>$lines,'shipments'=>$shipments,'cost'=>self::amount($order['total_price'] ?? null),'shipping_cost'=>self::amount($order['total_shipping'] ?? null),'production_certified'=>false];
    }
    private static function amount(mixed $value): ?int
    {
        if ($value === null) { return null; }
        if (!is_int($value) || $value < 0) { throw new \InvalidArgumentException('Invalid monetary evidence.'); }
        return $value;
    }
    private static function text(mixed $value, int $max): void
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value)) { throw new \InvalidArgumentException('Bounded text required.'); }
    }
}
