<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!\defined('ABSPATH')) {
    exit;
}

/** Fixed disposable scenario. One fresh in-memory provider per invocation. */
final class SyntheticJourney
{
    public static function run(string $shop): array
    {
        $steps = [];
        $result = [
            'state' => 'SYNTHETIC_INCOMPLETE', 'steps' => [],
            'provider_outcome_confirmed' => false, 'provider_execution_authorized' => false, 'retry_permitted' => false,
        ];
        try {
            $transport = new SyntheticPrintifyTransport($shop);
            $client = new SyntheticPrintifyClient($shop, $transport);
            $perform = static function (string $operation, array $input = []) use ($client, &$steps): array {
                $reply = $client->run($operation, $input);
                $steps[] = ['operation'=>$operation, 'status'=>$reply['status'] ?? 0, 'state'=>$reply['state']];
                if ($reply['state'] !== 'SYNTHETIC_OBSERVED') {
                    throw new \RuntimeException('Fixture step did not complete');
                }
                return $reply['data'];
            };
            $perform('SHOPS_READ');
            $perform('BLUEPRINT_READ', ['blueprint_id'=>42]);
            $perform('VARIANTS_READ', ['blueprint_id'=>42,'provider_id'=>7]);
            $image = $perform('IMAGE_UPLOAD', ['file_name'=>'fixture.png','contents'=>self::image()]);
            $perform('IMAGE_READ', ['image_id'=>$image['id']]);
            $product = $perform('PRODUCT_CREATE', [
                'title'=>'Synthetic product','description'=>'Fixture only','blueprint_id'=>42,'print_provider_id'=>7,
                'variants'=>[['id'=>9,'price'=>1000,'is_enabled'=>true]],
                'print_areas'=>[['variant_ids'=>[9],'placeholders'=>[['position'=>'front',
                    'images'=>[['id'=>$image['id'],'x'=>0.5,'y'=>0.5,'scale'=>1,'angle'=>0]]]]]],
            ]);
            $perform('PRODUCT_READ', ['product_id'=>$product['id'],'blueprint_id'=>42,'provider_id'=>7,'variant_ids'=>[9]]);
            $order = self::order($product['id']);
            $rates = $perform('ORDER_SHIPPING_QUOTE', ['order'=>$order]);
            $created = $perform('ORDER_CREATE', $order);
            $seen = $perform('ORDER_READ', ['order_id'=>$created['id']]);
            if ($seen['external_id'] !== $order['external_id'] || !$transport->confirmProductionSimulation($created['id'])) {
                throw new \RuntimeException('Fixture order identity differs');
            }
            $perform('ORDER_SEND_TO_PRODUCTION', ['order_id'=>$created['id']]);
            if (!$transport->fulfill($created['id'])) {
                throw new \RuntimeException('Fixture fulfillment denied');
            }
            $tracked = $perform('ORDER_READ', ['order_id'=>$created['id']]);
            if ($tracked['status'] !== 'fulfilled' || $tracked['external_id'] !== $order['external_id']
                || $tracked['line_items'] !== $seen['line_items'] || ($tracked['shipments'] ?? []) === []) {
                throw new \RuntimeException('Fixture readback does not reconcile');
            }
            $steps[] = ['operation'=>'SYNTHETIC_RECONCILIATION','status'=>200,'state'=>'SYNTHETIC_OBSERVED'];
            $result['summary'] = [
                'product_id'=>$product['id'],'order_id'=>$created['id'],'order_status'=>$tracked['status'],
                'shipping_standard_minor'=>$rates['rates_minor']['standard'],'shipment_count'=>count($tracked['shipments']),
            ];
            $result['state'] = 'SYNTHETIC_COMPLETE';
        } catch (\Throwable) {
            if (($steps[array_key_last($steps)]['state'] ?? null) === 'UNKNOWN') {
                $result['state'] = 'UNKNOWN';
            }
        }
        $result['steps'] = $steps;
        return $result;
    }

    private static function image(): string
    {
        $chunk = static fn(string $kind, string $bytes): string =>
            pack('N', strlen($bytes)).$kind.$bytes.hash('crc32b', $kind.$bytes, true);
        return base64_encode("\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress("\0\xff\0\0")).$chunk('IEND', ''));
    }

    private static function order(string $product): array
    {
        return [
            'external_id'=>'synthetic-journey-order','line_items'=>[['product_id'=>$product,'variant_id'=>9,'quantity'=>1]],
            'shipping_method'=>1,'is_printify_express'=>false,'is_economy_shipping'=>false,'send_shipping_notification'=>false,
            'address_to'=>['first_name'=>'Fixture','last_name'=>'Only','email'=>'fixture@example.test','phone'=>'',
                'country'=>'US','region'=>'CA','address1'=>'Synthetic street','address2'=>'','city'=>'Fixture','zip'=>'90001'],
        ];
    }
}
