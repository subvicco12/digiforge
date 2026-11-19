<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!\defined('ABSPATH')) {
    exit;
}

/** A bounded, in-memory Printify fixture. Its only I/O is the returned PHP array. */
final class SyntheticPrintifyTransport
{
    private const MAX_REQUESTS = 100;
    public const WEBHOOK_URLS = ['https://webhooks.example.invalid/printify', 'https://webhooks.example.invalid/printify-updated'];
    public const WEBHOOK_TOPICS = ['product:created','product:updated','order:created','order:updated',
        'order:shipment:created','order:shipment:delivered'];
    private const SHOP_TITLE = 'Synthetic shop';
    private const BLUEPRINT = ['id'=>42, 'title'=>'Synthetic mug', 'description'=>'Local fixture', 'brand'=>'Synthetic', 'model'=>'Mug'];
    private const PROVIDER = ['id'=>7, 'title'=>'Synthetic printer', 'decoration_methods'=>['sublimation'], 'production_time'=>null];
    private const VARIANT = ['id'=>9, 'title'=>'Synthetic white mug', 'options'=>['color'=>'White'], 'placeholders'=>[['position'=>'front','width'=>1200,'height'=>600]]];

    private array $images = [];
    private array $products = [];
    private array $orders = [];
    private array $webhooks = [];
    private int $nextWebhook = 1;
    private array $duplicates = [];
    private array $productionConfirmations = [];
    private int $requests = 0;
    private ?string $nextFault = null;

    public function __construct(private readonly string $shop)
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $shop) !== 1 || (string)(int)$shop !== $shop) {
            throw new \InvalidArgumentException('Exact synthetic shop required.');
        }
    }

    public function faultNext(string $fault): void
    {
        if (!in_array($fault, ['timeout','lost_ack','validation_error','rate_limit'], true)) {
            throw new \InvalidArgumentException('Unknown synthetic fault.');
        }
        $this->nextFault = $fault;
    }

    /** The caller explicitly selects an order for a separate synthetic production action. */
    public function confirmProductionSimulation(string $orderId): bool
    {
        if (($this->orders[$orderId]['status'] ?? null) !== 'on-hold') {
            return false;
        }
        $this->productionConfirmations[$orderId] = true;
        return true;
    }

    public function fulfill(string $orderId): bool
    {
        if (($this->orders[$orderId]['status'] ?? null) !== 'in-production') {
            return false;
        }
        $this->orders[$orderId]['status'] = 'fulfilled';
        $this->orders[$orderId]['shipments'] = [[
            'carrier'=>'Synthetic carrier', 'number'=>'SYNTHETIC-'.$orderId,
            'url'=>'https://tracking.example.invalid/'.$orderId, 'delivered_at'=>null,
        ]];
        return true;
    }

    public function request(array $plan): array
    {
        $operation = $plan['operation'] ?? null;
        $method = $plan['method'] ?? null;
        $endpoint = $plan['endpoint'] ?? null;
        $body = $plan['body'] ?? null;
        if (($plan['provider_shop_id'] ?? null) !== $this->shop
            || !is_string($operation) || !is_string($method) || !is_string($endpoint) || !is_array($body)) {
            return self::error(403, 'scope_denied');
        }
        if (++$this->requests > self::MAX_REQUESTS) {
            return self::error(429, 'fixture_budget_exhausted');
        }
        if (strlen($operation) > 50 || strlen($endpoint) > 300 || !self::safeBody($body)) {
            return self::error(422, 'fixture_body_invalid');
        }
        $version = $plan['expected_version'] ?? null;
        if (array_key_exists('expected_version', $plan)
            && ($operation !== 'PRODUCT_UPDATE' || !is_int($version) || $version < 1 || $version > 1000)) {
            return self::error(422, 'fixture_version_invalid');
        }
        $route = $this->route($operation, $method, $endpoint);
        if ($route === null || ($method === 'GET' && $body !== [])) {
            return self::error(404, 'route_unavailable');
        }
        $fault = $this->nextFault;
        $this->nextFault = null;
        if ($fault === 'rate_limit') {
            return self::error(429, 'fixture_rate_limit');
        }
        if ($fault === 'validation_error') {
            return self::error(422, 'fixture_validation_error');
        }
        if ($fault === 'timeout') {
            return self::response(0, []);
        }
        try {
            $result = $this->dispatch($operation, $route, $body, $version);
        } catch (\Throwable) {
            return self::error(422, 'fixture_validation_error');
        }
        return $fault === 'lost_ack' && $result['status'] >= 200 && $result['status'] < 300
            ? self::response(0, []) : $result;
    }

    private function route(string $operation, string $method, string $endpoint): ?array
    {
        $shopRoot = '/v1/shops/'.$this->shop;
        $id = '([A-Za-z0-9_-]{1,80})';
        $paging = '(?:\?page=([1-9][0-9]{0,5})&limit=([1-9]|10))?';
        $route = match ($operation) {
            'SHOPS_READ' => ['GET', '/v1/shops\.json'],
            'BLUEPRINTS_READ' => ['GET', '/v1/catalog/blueprints\.json'],
            'BLUEPRINT_READ' => ['GET', '/v1/catalog/blueprints/42\.json'],
            'PROVIDERS_READ', 'BLUEPRINT_PROVIDERS_READ' => ['GET', '/v1/catalog/(?:blueprints/42/)?print_providers\.json'],
            'PROVIDER_READ', 'BLUEPRINT_PROVIDER_READ' => ['GET', '/v1/catalog/(?:blueprints/42/)?print_providers/7\.json'],
            'VARIANTS_READ', 'VARIANT_READ' => ['GET', '/v1/catalog/blueprints/42/print_providers/7/variants\.json'],
            'SIZE_GUIDE_READ' => ['GET', '/v1/catalog/blueprints/42/size_guide\.json'],
            'CATALOG_SHIPPING_READ' => ['GET', '/v2/catalog/blueprints/42/print_providers/7/shipping\.json'],
            'CATALOG_SHIPPING_METHOD_READ' => ['GET', '/v2/catalog/blueprints/42/print_providers/7/shipping/(standard|priority|express|economy)\.json'],
            'IMAGE_UPLOAD' => ['POST', '/v1/uploads/images\.json'],
            'IMAGE_READ' => ['GET', '/v1/uploads/'.$id.'\.json'],
            'UPLOADS_READ' => ['GET', '/v1/uploads\.json'.$paging],
            'PRODUCT_CREATE' => ['POST', $shopRoot.'/products\.json'],
            'PRODUCT_READ' => ['GET', $shopRoot.'/products/'.$id.'\.json'],
            'PRODUCT_UPDATE' => ['PUT', $shopRoot.'/products/'.$id.'\.json'],
            'PRODUCTS_READ' => ['GET', $shopRoot.'/products\.json'.$paging],
            'ORDER_SHIPPING_QUOTE', 'SHIPPING_QUOTE' => ['POST', $shopRoot.'/orders/shipping\.json'],
            'ORDER_CREATE', 'ORDER_SUBMIT' => ['POST', $shopRoot.'/orders\.json'],
            'ORDER_READ' => ['GET', $shopRoot.'/orders/'.$id.'\.json'],
            'ORDERS_READ' => ['GET', $shopRoot.'/orders\.json'.$paging],
            'ORDER_CANCEL' => ['POST', $shopRoot.'/orders/'.$id.'/cancel\.json'],
            'ORDER_SEND_TO_PRODUCTION', 'PRODUCTION_AUTHORIZE' => ['POST', $shopRoot.'/orders/'.$id.'/send_to_production\.json'],
            'WEBHOOKS_READ', 'WEBHOOK_READ' => ['GET', $shopRoot.'/webhooks\.json'],
            'WEBHOOK_CREATE' => ['POST', $shopRoot.'/webhooks\.json'],
            'WEBHOOK_UPDATE' => ['PUT', $shopRoot.'/webhooks/'.$id.'\.json'],
            'WEBHOOK_DELETE' => ['DELETE', $shopRoot.'/webhooks/'.$id.'\.json\?host=webhooks\.example\.invalid'],
            default => null,
        };
        return $route !== null && $method === $route[0] && preg_match('~^'.$route[1].'$~D', $endpoint, $matches) === 1
            ? array_slice($matches, 1) : null;
    }

    private function dispatch(string $operation, array $route, array $body, ?int $version): array
    {
        return match ($operation) {
            'SHOPS_READ' => self::response(200, [['id'=>(int)$this->shop, 'title'=>self::SHOP_TITLE, 'sales_channel'=>'api']]),
            'BLUEPRINTS_READ' => self::response(200, [self::BLUEPRINT]),
            'BLUEPRINT_READ' => self::response(200, self::BLUEPRINT),
            'PROVIDERS_READ', 'BLUEPRINT_PROVIDERS_READ' => self::response(200, [self::PROVIDER]),
            'PROVIDER_READ', 'BLUEPRINT_PROVIDER_READ' => self::response(200, self::PROVIDER),
            'VARIANTS_READ', 'VARIANT_READ' => self::response(200, ['id'=>42,'title'=>self::BLUEPRINT['title'],'variants'=>[self::VARIANT]]),
            'SIZE_GUIDE_READ' => self::response(200, ['sizes'=>['11oz'],'types'=>[
                ['name'=>'Height','units'=>'cm','ranges'=>[['from'=>9.5,'to'=>9.5]]],
            ]]),
            'CATALOG_SHIPPING_READ' => self::response(200, ['data'=>[
                ['id'=>'1','type'=>'shipping_method','attributes'=>['name'=>'standard']],
            ]]),
            'CATALOG_SHIPPING_METHOD_READ' => self::catalogShipping($route[0]),
            'IMAGE_UPLOAD' => $this->upload($body),
            'IMAGE_READ' => self::lookup($this->images, $route[0]),
            'UPLOADS_READ' => self::page($this->images, $route),
            'PRODUCT_CREATE' => $this->createProduct($body),
            'PRODUCT_READ' => self::lookup($this->products, $route[0]),
            'PRODUCT_UPDATE' => $this->updateProduct($route[0], $body, $version),
            'PRODUCTS_READ' => self::page($this->products, $route),
            'ORDER_SHIPPING_QUOTE', 'SHIPPING_QUOTE' => $this->quote($body),
            'ORDER_CREATE', 'ORDER_SUBMIT' => $this->createOrder($body),
            'ORDER_READ' => self::lookup($this->orders, $route[0]),
            'ORDERS_READ' => self::page($this->orders, $route),
            'ORDER_CANCEL' => $this->cancel($route[0]),
            'ORDER_SEND_TO_PRODUCTION', 'PRODUCTION_AUTHORIZE' => $this->produce($route[0]),
            'WEBHOOKS_READ', 'WEBHOOK_READ' => self::response(200, array_values($this->webhooks)),
            'WEBHOOK_CREATE' => $this->createWebhook($body),
            'WEBHOOK_UPDATE' => $this->updateWebhook($route[0],$body),
            'WEBHOOK_DELETE' => $this->deleteWebhook($route[0],$body),
        };
    }

    private function upload(array $body): array
    {
        $file = $body['file_name'] ?? null;
        $encoded = $body['contents'] ?? null;
        if (!is_string($file) || strlen($file) > 120 || !is_string($encoded) || strlen($encoded) > 2796204) {
            return self::error(422, 'image_invalid');
        }
        $bytes = base64_decode($encoded, true);
        $size = is_string($bytes) && str_starts_with($bytes, "\x89PNG\r\n\x1a\n") ? @getimagesizefromstring($bytes) : false;
        if (!is_array($size) || $size[2] !== IMAGETYPE_PNG || $size[0] < 1 || $size[1] < 1) {
            return self::error(422, 'image_invalid');
        }
        $key = 'image:'.hash('sha256', $bytes);
        if (isset($this->duplicates[$key])) {
            return self::error(409, 'duplicate_image');
        }
        $id = 'image-'.(count($this->images)+1);
        $row = ['id'=>$id, 'file_name'=>$file, 'width'=>$size[0], 'height'=>$size[1], 'size'=>strlen($bytes),
            'mime_type'=>'image/png', 'preview_url'=>'https://images.example.invalid/'.$id.'.png', 'upload_time'=>'2026-11-19 00:00:00'];
        $this->images[$id] = $row;
        $this->duplicates[$key] = $id;
        return self::response(201, $row);
    }

    private function createProduct(array $body): array
    {
        if (!$this->validProduct($body)) {
            return self::error(422, 'product_invalid');
        }
        $key = 'product:'.hash('sha256', json_encode($body, JSON_THROW_ON_ERROR, 24));
        if (isset($this->duplicates[$key])) {
            return self::error(409, 'duplicate_product');
        }
        $id = 'product-'.(count($this->products)+1);
        $row = array_intersect_key($body, array_flip(['title','description','blueprint_id','print_provider_id','variants','print_areas']));
        $row += ['id'=>$id, 'shop_id'=>(int)$this->shop, 'images'=>[], 'visible'=>false, 'synthetic_version'=>1];
        $this->products[$id] = $row;
        $this->duplicates[$key] = $id;
        return self::response(201, $row);
    }

    private function updateProduct(string $id, array $body, ?int $version): array
    {
        if (!isset($this->products[$id])) {
            return self::error(404, 'product_not_found');
        }
        if ($version !== null && $this->products[$id]['synthetic_version'] !== $version) {
            return self::error(409, 'stale_product_version');
        }
        if ($body === [] || array_diff(array_keys($body), ['title','description','blueprint_id','print_provider_id','variants','print_areas'])) {
            return self::error(422, 'product_invalid');
        }
        $row = array_replace($this->products[$id], $body);
        if (!$this->validProduct($row)) {
            return self::error(422, 'product_invalid');
        }
        ++$row['synthetic_version'];
        $this->products[$id] = $row;
        return self::response(200, $row);
    }

    private function validProduct(array $row): bool
    {
        if (($row['blueprint_id'] ?? null) !== 42 || ($row['print_provider_id'] ?? null) !== 7
            || !is_string($row['title'] ?? null) || !is_string($row['description'] ?? null)
            || !is_array($row['variants'] ?? null) || array_column($row['variants'], 'id') !== [9]
            || !is_array($row['print_areas'] ?? null) || $row['print_areas'] === []) {
            return false;
        }
        foreach ($row['print_areas'] as $area) {
            if (!is_array($area) || ($area['variant_ids'] ?? null) !== [9] || !is_array($area['placeholders'] ?? null)) {
                return false;
            }
            foreach ($area['placeholders'] as $holder) {
                if (($holder['position'] ?? null) !== 'front' || !is_array($holder['images'] ?? null) || $holder['images'] === []) {
                    return false;
                }
                foreach ($holder['images'] as $image) {
                    if (!is_string($image['id'] ?? null) || !isset($this->images[$image['id']])) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    private function orderLines(mixed $lines): ?array
    {
        if (!is_array($lines) || !array_is_list($lines) || $lines === [] || count($lines) > 50) {
            return null;
        }
        $copy = [];
        foreach ($lines as $line) {
            if (!is_array($line) || !is_string($line['product_id'] ?? null) || ($line['variant_id'] ?? null) !== 9
                || !is_int($line['quantity'] ?? null) || $line['quantity'] < 1 || $line['quantity'] > 100
                || !isset($this->products[$line['product_id']])) {
                return null;
            }
            $product = $this->products[$line['product_id']];
            if (($product['variants'][0]['is_enabled'] ?? null) !== true) {
                return null;
            }
            $copy[] = array_intersect_key($line, array_flip(['product_id','variant_id','quantity']));
        }
        return $copy;
    }

    private function quote(array $body): array
    {
        $lines = $this->orderLines($body['line_items'] ?? null);
        if ($lines === null || ($body['address_to']['country'] ?? null) !== 'US') {
            return self::error(422, 'shipping_unavailable');
        }
        $cost = 500 + (array_sum(array_column($lines, 'quantity')) - 1) * 200;
        return self::response(200, ['standard'=>$cost]);
    }

    private function createOrder(array $body): array
    {
        $external = $body['external_id'] ?? null;
        $lines = $this->orderLines($body['line_items'] ?? null);
        if (!is_string($external) || preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $external) !== 1
            || $lines === null || ($body['address_to']['country'] ?? null) !== 'US') {
            return self::error(422, 'order_invalid');
        }
        $key = 'order:'.$external;
        if (isset($this->duplicates[$key])) {
            return self::error(409, 'duplicate_order');
        }
        $id = 'order-'.(count($this->orders)+1);
        $row = ['id'=>$id,'external_id'=>$external,'shop_id'=>(int)$this->shop,'status'=>'on-hold',
            'line_items'=>$lines,'shipments'=>[],'sent_to_production_at'=>null];
        $this->orders[$id] = $row;
        $this->duplicates[$key] = $id;
        return self::response(201, $row);
    }

    private function cancel(string $id): array
    {
        if (!isset($this->orders[$id])) {
            return self::error(404, 'order_not_found');
        }
        if ($this->orders[$id]['status'] !== 'on-hold') {
            return self::error(409, 'order_not_cancelable');
        }
        $this->orders[$id]['status'] = 'canceled';
        unset($this->productionConfirmations[$id]);
        return self::response(200, $this->orders[$id]);
    }

    private function produce(string $id): array
    {
        if (($this->orders[$id]['status'] ?? null) !== 'on-hold' || !isset($this->productionConfirmations[$id])) {
            return self::error(409, 'production_simulation_not_confirmed');
        }
        unset($this->productionConfirmations[$id]);
        $this->orders[$id]['status'] = 'in-production';
        $this->orders[$id]['sent_to_production_at'] = '2026-11-19T00:00:00Z';
        return self::response(200, $this->orders[$id]);
    }

    private function createWebhook(array $body): array
    {
        if (count($body)!==2 || !in_array($body['topic']??null,self::WEBHOOK_TOPICS,true)
            || !in_array($body['url']??null,self::WEBHOOK_URLS,true)) {
            return self::error(422,'webhook_invalid');
        }
        foreach ($this->webhooks as $subscription) {
            if ($subscription['topic']===$body['topic'] && $subscription['url']===$body['url']) {
                return self::error(409,'duplicate_webhook');
            }
        }
        $id='webhook-'.$this->nextWebhook++;
        $row=['id'=>$id,'shop_id'=>(int)$this->shop,'topic'=>$body['topic'],'url'=>$body['url']];
        $this->webhooks[$id]=$row;
        return self::response(201,$row);
    }

    private function updateWebhook(string $id,array $body): array
    {
        if (!isset($this->webhooks[$id])) {
            return self::error(404,'webhook_not_found');
        }
        if (count($body)!==1 || !in_array($body['url']??null,self::WEBHOOK_URLS,true)) {
            return self::error(422,'webhook_invalid');
        }
        foreach ($this->webhooks as $other=>$subscription) {
            if ($other!==$id && $subscription['topic']===$this->webhooks[$id]['topic'] && $subscription['url']===$body['url']) {
                return self::error(409,'duplicate_webhook');
            }
        }
        $this->webhooks[$id]['url']=$body['url'];
        return self::response(200,$this->webhooks[$id]);
    }

    private function deleteWebhook(string $id,array $body): array
    {
        if ($body!==[]) {
            return self::error(422,'webhook_invalid');
        }
        if (!isset($this->webhooks[$id])) {
            return self::error(404,'webhook_not_found');
        }
        unset($this->webhooks[$id]);
        return self::response(200,['id'=>$id]);
    }

    private static function catalogShipping(string $method): array
    {
        return self::response(200, ['data'=>$method==='standard' ? [[
            'id'=>'9','type'=>'variant_shipping_standard_us','attributes'=>[
                'shippingType'=>'standard','country'=>['code'=>'US'],'variantId'=>9,
                'shippingPlanId'=>'synthetic-standard','handlingTime'=>['from'=>2,'to'=>5],
                'shippingCost'=>['firstItem'=>['amount'=>500,'currency'=>'USD'],
                    'additionalItems'=>['amount'=>200,'currency'=>'USD']],
            ],
        ]] : []]);
    }

    private static function safeBody(mixed $value, int $depth = 0, int &$remaining = 3000000): bool
    {
        if ($depth > 24 || --$remaining < 0) {
            return false;
        }
        if (is_array($value)) {
            if (count($value) > 1000) {
                return false;
            }
            foreach ($value as $key=>$child) {
                $remaining -= is_string($key) ? strlen($key) : 1;
                if (!self::safeBody($child, $depth + 1, $remaining)) {
                    return false;
                }
            }
            return true;
        }
        if (is_string($value)) {
            $remaining -= strlen($value);
            return $remaining >= 0;
        }
        return $value === null || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value));
    }

    private static function lookup(array $rows, string $id): array
    {
        return isset($rows[$id]) ? self::response(200, $rows[$id]) : self::error(404, 'fixture_resource_not_found');
    }

    private static function page(array $rows, array $route): array
    {
        $page = isset($route[0]) ? (int)$route[0] : 1;
        $limit = isset($route[1]) ? (int)$route[1] : 10;
        return self::response(200, ['data'=>array_slice(array_values($rows), ($page-1)*$limit, $limit),
            'current_page'=>$page,'per_page'=>$limit,'last_page'=>max($page, (int)ceil(count($rows)/$limit)), 'total'=>count($rows)]);
    }

    private static function error(int $status, string $code): array
    {
        return self::response($status, ['status'=>'error','code'=>$code,'message'=>'Synthetic operation denied.']);
    }

    private static function response(int $status, array $body): array
    {
        return ['status'=>$status,'body'=>$body,'headers'=>$status===429?['retry-after'=>'1']:[],
            'synthetic'=>true,'provider_execution_authorized'=>false,'retry_permitted'=>false];
    }
}
