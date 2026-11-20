<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

use DigiForgePrintifyApi\Engine\Listings\Validator;
use DigiForgePrintifyApi\Engine\POD\{PrintifyProductRequest, PrintifySupportContract};

if (!\defined('ABSPATH')) {
    exit;
}

/** A bounded, in-memory Printify fixture. Its only I/O is the returned PHP array. */
final class SyntheticPrintifyTransport
{
    private const MAX_REQUESTS = 100;
    public const WEBHOOK_URLS = ['https://webhooks.example.invalid/printify', 'https://webhooks.example.invalid/printify-updated'];
    public const WEBHOOK_TOPICS = ['product:created','product:updated','order:created','order:updated',
        'order:shipment:created','order:shipment:delivered','shop:disconnected','product:deleted',
        'order:line-item:canceled','order:sent-to-production','product:publish:started',
        'personalization-preview-task:processed','support-request:updated'];
    private const PERSONALIZATION_FIELD = 'fixture-inscription';
    private const PERSONALIZATION_IMAGE = 'fixture-artwork';
    private const PERSONALIZATION_MAX_CHARS = 80;
    private const SHOP_TITLE = 'Synthetic shop';
    private const BLUEPRINT = ['id'=>42, 'title'=>'Synthetic mug', 'description'=>'Local fixture', 'brand'=>'Synthetic', 'model'=>'Mug'];
    private const PROVIDER = ['id'=>7, 'title'=>'Synthetic printer', 'decoration_methods'=>['sublimation'], 'production_time'=>null];
    private const VARIANTS = [
        ['id'=>9, 'title'=>'Synthetic white mug', 'options'=>['color'=>'White'], 'placeholders'=>[
            ['position'=>'front','width'=>1200,'height'=>600], ['position'=>'back','width'=>1200,'height'=>600],
        ]],
        ['id'=>10, 'title'=>'Synthetic blue mug', 'options'=>['color'=>'Blue'], 'placeholders'=>[
            ['position'=>'front','width'=>1500,'height'=>750], ['position'=>'back','width'=>1400,'height'=>700],
        ]],
    ];

    private bool $connected = true;
    private ?array $simulatedEvent = null;
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

    /** Data-only state for the disposable, scope-sealed fixture store. */
    public function exportFixtureState(): array
    {
        return [$this->images, $this->products, $this->orders, $this->webhooks, $this->duplicates, $this->nextWebhook, $this->connected];
    }

    public function restoreFixtureState(array $state): void
    {
        if (array_is_list($state) && count($state)===6) {
            $state[]=true;
        }
        if (!array_is_list($state) || count($state) !== 7 || !is_bool($state[6])
            || $this->exportFixtureState() !== [[], [], [], [], [], 1, true]
            || !is_int($state[5]) || $state[5] < 1 || $state[5] > 101) {
            throw new \InvalidArgumentException('Invalid synthetic state');
        }
        foreach (array_slice($state, 0, 5) as $collection) {
            if (!is_array($collection) || count($collection) > 100) {
                throw new \InvalidArgumentException('Synthetic state exceeds its bounds');
            }
        }
        foreach (array_slice($state, 0, 4) as $offset => $items) {
            $prefix = ['image-', 'product-', 'order-', 'webhook-'][$offset];
            foreach ($items as $id => $row) {
                if (!is_string($id) || !preg_match('/^'.$prefix.'[1-9][0-9]{0,2}$/D', $id) || !is_array($row)
                    || ($row['id'] ?? null) !== $id || ($offset !== 0 && ($row['shop_id'] ?? null) !== (int)$this->shop)) {
                    throw new \InvalidArgumentException('Synthetic identity mismatch');
                }
            }
        }
        [$this->images, $this->products, $this->orders, $this->webhooks, $this->duplicates, $this->nextWebhook, $this->connected] = $state;
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

    /** Fixed fixture metadata for an exact synthetic readback. */
    public function fixtureProductSelectors(string $id): ?array
    {
        $product=$this->availableProducts()[$id]??null;
        if ($product===null) {
            return null;
        }
        return [
            'product_id'=>$id, 'blueprint_id'=>$product['blueprint_id'],
            'provider_id'=>$product['print_provider_id'], 'variant_ids'=>array_column($product['variants'],'id'),
        ];
    }

    /** Record delivery only for a matching, already fulfilled fixture shipment. */
    public function recordFixtureDelivery(string $orderId,string $trackingSha256,string $deliveredAt): bool
    {
        if (($this->orders[$orderId]['status']??null)!=='fulfilled'
            ||preg_match('/^[a-f0-9]{64}$/D',$trackingSha256)!==1
            ||preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D',$deliveredAt)!==1) {
            return false;
        }
        $instant=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$deliveredAt,new \DateTimeZone('UTC'));
        if ($instant===false||$instant->format('Y-m-d\TH:i:s\Z')!==$deliveredAt) {
            return false;
        }
        $matches=[];
        foreach ($this->orders[$orderId]['shipments'] as $index=>$shipment) {
            if (is_string($shipment['number']??null)&&hash_equals(hash('sha256',$shipment['number']),$trackingSha256)) {
                $matches[]=$index;
            }
        }
        if (count($matches)!==1) {
            return false;
        }
        $index=$matches[0];
        $existing=$this->orders[$orderId]['shipments'][$index]['delivered_at'];
        if ($existing!==null&&$existing!==$deliveredAt) {
            return false;
        }
        $this->orders[$orderId]['shipments'][$index]['delivered_at']=$deliveredAt;
        return true;
    }

    public function request(array $plan): array
    {
        $this->simulatedEvent = null;
        $operation = $plan['operation'] ?? null;
        $method = $plan['method'] ?? null;
        $endpoint = $plan['endpoint'] ?? null;
        $body = $plan['body'] ?? null;
        if (($plan['provider_shop_id'] ?? null) !== $this->shop
            || !is_string($operation) || !is_string($method) || !is_string($endpoint) || !is_array($body)) {
            return self::error(403, 'scope_denied');
        }
        if (!$this->connected && $operation!=='SHOPS_READ') {
            return self::error(403,'shop_disconnected');
        }
        if (++$this->requests > self::MAX_REQUESTS) {
            return self::error(429, 'fixture_budget_exhausted');
        }
        if (strlen($operation) > 50 || strlen($endpoint) > 300 || !self::safeBody($body)) {
            return self::error(422, 'fixture_body_invalid');
        }
        $version = $plan['expected_version'] ?? null;
        if (array_key_exists('expected_version', $plan)
            && (!in_array($operation,['PRODUCT_UPDATE','PRODUCT_DELETE','PRODUCT_PUBLISH','PRODUCT_PUBLISH_SUCCEEDED','PRODUCT_PUBLISH_FAILED','PRODUCT_UNPUBLISH_NOTIFY'],true) || !is_int($version) || $version < 1 || $version > 1000)) {
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
            'SHOP_DISCONNECT' => ['DELETE', $shopRoot.'/connection\.json'],
            'ORDER_EXPRESS_CREATE' => ['POST', $shopRoot.'/orders/express\.json'],
            'WEBHOOK_SIMULATE' => ['POST', $shopRoot.'/webhooks/'.$id.'/simulate'],
            'SHOPS_READ' => ['GET', '/v1/shops\.json'],
            'BLUEPRINTS_READ' => ['GET', '/v1/catalog/blueprints\.json'],
            'BLUEPRINT_READ' => ['GET', '/v1/catalog/blueprints/42\.json'],
            'PROVIDERS_READ', 'BLUEPRINT_PROVIDERS_READ' => ['GET', '/v1/catalog/(?:blueprints/42/)?print_providers\.json'],
            'PROVIDER_READ', 'BLUEPRINT_PROVIDER_READ' => ['GET', '/v1/catalog/(?:blueprints/42/)?print_providers/7\.json'],
            'VARIANTS_READ', 'VARIANT_READ' => ['GET', '/v1/catalog/blueprints/42/print_providers/7/variants\.json'],
            'SIZE_GUIDE_READ' => ['GET', '/v1/catalog/blueprints/42/size_guide\.json'],
            'CATALOG_SHIPPING_V1_READ' => ['GET', '/v1/catalog/blueprints/42/print_providers/7/shipping\.json'],
            'CATALOG_SHIPPING_READ' => ['GET', '/v2/catalog/blueprints/42/print_providers/7/shipping\.json'],
            'CATALOG_SHIPPING_METHOD_READ' => ['GET', '/v2/catalog/blueprints/42/print_providers/7/shipping/(standard|priority|express|economy)\.json'],
            'IMAGE_UPLOAD' => ['POST', '/v1/uploads/images\.json'],
            'IMAGE_READ' => ['GET', '/v1/uploads/'.$id.'\.json'],
            'IMAGE_ARCHIVE' => ['POST', '/v1/uploads/'.$id.'/archive\.json'],
            'UPLOADS_READ' => ['GET', '/v1/uploads\.json'.$paging],
            'PRODUCT_CREATE' => ['POST', $shopRoot.'/products\.json'],
            'PERSONALIZATION_OPTIONS' => ['GET', $shopRoot.'/products/'.$id.'/personalization_options\.json'],
            'PERSONALIZATION_CONFIGURE' => ['POST', $shopRoot.'/products/'.$id.'/personalization\.json'],
            'PREVIEW_CREATE' => ['POST', $shopRoot.'/products/'.$id.'/personalization_previews\.json'],
            'PREVIEW_READ' => ['GET', $shopRoot.'/products/'.$id.'/personalization_previews/tasks/'.$id.'\.json'],
            'PRODUCT_GPSR_READ' => ['GET', $shopRoot.'/products/'.$id.'/gpsr\.json'],
            'PRODUCT_READ' => ['GET', $shopRoot.'/products/'.$id.'\.json'],
            'PRODUCT_UPDATE' => ['PUT', $shopRoot.'/products/'.$id.'\.json'],
            'PRODUCT_DELETE' => ['DELETE', $shopRoot.'/products/'.$id.'\.json'],
            'PRODUCT_PUBLISH' => ['POST', $shopRoot.'/products/'.$id.'/publish\.json'],
            'PRODUCT_PUBLISH_SUCCEEDED' => ['POST', $shopRoot.'/products/'.$id.'/publishing_succeeded\.json'],
            'PRODUCT_PUBLISH_FAILED' => ['POST', $shopRoot.'/products/'.$id.'/publishing_failed\.json'],
            'PRODUCT_UNPUBLISH_NOTIFY' => ['POST', $shopRoot.'/products/'.$id.'/unpublish\.json'],
            'PRODUCTS_READ' => ['GET', $shopRoot.'/products\.json'.$paging],
            'ORDER_SHIPPING_QUOTE', 'SHIPPING_QUOTE' => ['POST', $shopRoot.'/orders/shipping\.json'],
            'ORDER_CREATE', 'ORDER_SUBMIT' => ['POST', $shopRoot.'/orders\.json'],
            'ORDER_READ' => ['GET', $shopRoot.'/orders/'.$id.'\.json'],
            'ORDERS_READ' => ['GET', $shopRoot.'/orders\.json'.$paging],
            'LINE_ITEM_CANCEL' => ['POST', $shopRoot.'/orders/'.$id.'/line_items/'.$id.'/cancel\.json'],
            'ORDER_CANCEL' => ['POST', $shopRoot.'/orders/'.$id.'/cancel\.json'],
            'ORDER_ADDRESS_CHANGE' => ['POST', $shopRoot.'/orders/'.$id.'/address_change\.json'],
            'SUPPORT_LIST' => ['GET', $shopRoot.'/orders/'.$id.'/support-requests'],
            'SUPPORT_DETAIL' => ['GET', $shopRoot.'/orders/'.$id.'/support-requests/'.$id],
            'SUPPORT_REFUND' => ['POST', $shopRoot.'/orders/'.$id.'/support-requests/refund'],
            'SUPPORT_REPRINT' => ['POST', $shopRoot.'/orders/'.$id.'/support-requests/reprint'],
            'SUPPORT_ADDRESS_CHANGE' => ['POST', $shopRoot.'/orders/'.$id.'/support-requests/address-change'],
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
            'SHOP_DISCONNECT' => $this->disconnect($body),
            'ORDER_EXPRESS_CREATE' => $this->createExpress($body),
            'WEBHOOK_SIMULATE' => $this->simulateWebhook($route[0],$body),
            'SHOPS_READ' => self::response(200, $this->connected ? [['id'=>(int)$this->shop, 'title'=>self::SHOP_TITLE, 'sales_channel'=>'api']] : []),
            'BLUEPRINTS_READ' => self::response(200, [self::BLUEPRINT]),
            'BLUEPRINT_READ' => self::response(200, self::BLUEPRINT),
            'PROVIDERS_READ', 'BLUEPRINT_PROVIDERS_READ' => self::response(200, [self::PROVIDER]),
            'PROVIDER_READ', 'BLUEPRINT_PROVIDER_READ' => self::response(200, self::PROVIDER),
            'VARIANTS_READ', 'VARIANT_READ' => self::response(200, ['id'=>42,'title'=>self::BLUEPRINT['title'],'variants'=>self::VARIANTS]),
            'SIZE_GUIDE_READ' => self::response(200, ['sizes'=>['11oz'],'types'=>[
                ['name'=>'Height','units'=>'cm','ranges'=>[['from'=>9.5,'to'=>9.5]]],
            ]]),
            'CATALOG_SHIPPING_V1_READ' => self::response(200,[
                'handling_time'=>['value'=>3,'unit'=>'day'],
                'profiles'=>[['variant_ids'=>[9,10],'first_item'=>['cost'=>500,'currency'=>'USD'],
                    'additional_items'=>['cost'=>200,'currency'=>'USD'],'countries'=>['US']]],
            ]),
            'CATALOG_SHIPPING_READ' => self::response(200, ['data'=>[
                ['id'=>'1','type'=>'shipping_method','attributes'=>['name'=>'standard']],
            ]]),
            'CATALOG_SHIPPING_METHOD_READ' => self::catalogShipping($route[0]),
            'IMAGE_UPLOAD' => $this->upload($body),
            'IMAGE_READ' => self::lookup($this->availableImages(), $route[0]),
            'UPLOADS_READ' => self::page($this->availableImages(), $route),
            'IMAGE_ARCHIVE' => $this->archiveImage($route[0], $body),
            'PRODUCT_CREATE' => $this->createProduct($body),
            'PERSONALIZATION_OPTIONS','PERSONALIZATION_CONFIGURE','PREVIEW_CREATE','PREVIEW_READ'
                => $this->personalization($operation,$route,$body),
            'PRODUCT_GPSR_READ' => isset($this->availableProducts()[$route[0]])
                ? self::response(200, []) : self::error(404, 'fixture_resource_not_found'),
            'PRODUCT_READ' => self::lookup($this->availableProducts(), $route[0]),
            'PRODUCT_UPDATE' => $this->updateProduct($route[0], $body, $version),
            'PRODUCTS_READ' => self::page($this->availableProducts(), $route),
            'PRODUCT_DELETE' => $this->deleteProduct($route[0], $body, $version),
            'PRODUCT_PUBLISH' => $this->publishProduct($route[0], $body, $version),
            'PRODUCT_PUBLISH_SUCCEEDED','PRODUCT_PUBLISH_FAILED','PRODUCT_UNPUBLISH_NOTIFY'
                => $this->resolvePublication($route[0],$operation,$body,$version),
            'ORDER_SHIPPING_QUOTE', 'SHIPPING_QUOTE' => $this->quote($body),
            'ORDER_CREATE', 'ORDER_SUBMIT' => $this->createOrder($body),
            'ORDER_READ' => self::lookup($this->orders, $route[0]),
            'ORDERS_READ' => self::page($this->orders, $route),
            'LINE_ITEM_CANCEL' => $body === [] ? $this->cancelLine($route[0],$route[1]) : self::error(422,'cancel_body'),
            'ORDER_CANCEL' => $this->cancel($route[0]),
            'ORDER_ADDRESS_CHANGE' => $this->changeOrderAddress($route[0],$body),
            'SUPPORT_LIST','SUPPORT_DETAIL','SUPPORT_REFUND','SUPPORT_REPRINT','SUPPORT_ADDRESS_CHANGE'
                => $this->support($operation,$route,$body),
            'ORDER_SEND_TO_PRODUCTION', 'PRODUCTION_AUTHORIZE' => $this->produce($route[0]),
            'WEBHOOKS_READ', 'WEBHOOK_READ' => self::response(200, array_values($this->webhooks)),
            'WEBHOOK_CREATE' => $this->createWebhook($body),
            'WEBHOOK_UPDATE' => $this->updateWebhook($route[0],$body),
            'WEBHOOK_DELETE' => $this->deleteWebhook($route[0],$body),
        };
    }

    private function disconnect(array $body): array
    {
        if ($body!==[]) {
            return self::error(422,'disconnect_body_not_empty');
        }
        $this->connected=false;
        return self::response(200,[]);
    }

    private function createExpress(array $body): array
    {
        SyntheticPrintifyClient::validateExpress($body);
        $order=$this->createOrder($body);
        if ($order['status']!==201) {
            return $order;
        }
        $row=$order['body'];
        $lines=array_map(static fn(array $line):array=>$line+['status'=>'pending'],$row['line_items']);
        return self::response(200,['data'=>[['type'=>'order','id'=>$row['id'],
            'attributes'=>['fulfilment_type'=>'express','line_items'=>$lines]]]]);
    }

    private function simulateWebhook(string $id,array $body): array
    {
        if ($body!==[]) {
            return self::error(422,'simulate_body_not_empty');
        }
        $topic=$this->webhooks[$id]['topic']??null;
        if ($topic===null) {
            return self::error(404,'webhook_not_found');
        }
        $resourceType=str_starts_with($topic,'product:')?'product':'order';
        $rows=$resourceType==='product'?$this->availableProducts():$this->orders;
        $row=end($rows);
        if ($row===false||!in_array($topic,['order:created','order:updated','product:created','product:updated'],true)) {
            return self::error(409,'simulation_resource_unavailable');
        }
        $data=['shop_id'=>(int)$this->shop];
        if ($topic==='order:updated') {
            $data['status']=$row['status'];
        }
        $fingerprint=hash('sha256',$this->shop.':'.$id.':'.$topic.':'.$row['id']);
        $eventId=vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(substr($fingerprint,0,32),4));
        $this->simulatedEvent=['id'=>$eventId,'type'=>$topic,'created_at'=>'2026-11-19 00:00:00+00:00',
            'resource'=>['id'=>$row['id'],'type'=>$resourceType,'data'=>$data]];
        return self::response(204,[]);
    }

    public function takeSimulatedEvent(): ?array
    {
        $event=$this->simulatedEvent;
        $this->simulatedEvent=null;
        return $event;
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
        if (!isset($this->availableProducts()[$id])) {
            return self::error(404, 'product_not_found');
        }
        if ($version !== null && $this->products[$id]['synthetic_version'] !== $version) {
            return self::error(409, 'stale_product_version');
        }
        if (($this->products[$id]['synthetic_publish_pending']??false)===true) {
            return self::error(409,'product_publication_pending');
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

    private function availableImages(): array
    {
        return array_filter($this->images, static fn(array $image): bool => ($image['archived']??false)!==true);
    }

    private function availableProducts(): array
    {
        return array_filter($this->products, static fn(array $product): bool => ($product['is_deleted']??false)!==true);
    }

    private function archiveImage(string $id,array $body): array
    {
        if ($body!==[]) {
            return self::error(422,'image_archive_invalid');
        }
        if (!isset($this->availableImages()[$id])) {
            return self::error(404,'image_not_found');
        }
        foreach ($this->availableProducts() as $product) {
            foreach ($product['print_areas'] as $area) {
                foreach ($area['placeholders'] as $placeholder) {
                    if (in_array($id,array_column($placeholder['images'],'id'),true)) {
                        return self::error(409,'image_in_use');
                    }
                }
            }
        }
        $this->images[$id]['archived']=true;
        return self::response(200,[]);
    }

    private function deleteProduct(string $id,array $body,?int $version): array
    {
        if ($body!==[]) {
            return self::error(422,'product_delete_invalid');
        }
        $product=$this->availableProducts()[$id]??null;
        if ($product===null) {
            return self::error(404,'product_not_found');
        }
        if ($version!==null&&$product['synthetic_version']!==$version) {
            return self::error(409,'stale_product_version');
        }
        if ($product['visible']||($product['synthetic_publish_pending']??false)) {
            return self::error(409,'product_publication_active');
        }
        foreach ($this->orders as $order) {
            if (in_array($id,array_column($order['line_items'],'product_id'),true)) {
                return self::error(409,'product_in_use');
            }
        }
        $this->products[$id]['is_deleted']=true;
        ++$this->products[$id]['synthetic_version'];
        return self::response(200,[]);
    }

    private function publishProduct(string $id,array $fields,?int $version): array
    {
        $product=$this->availableProducts()[$id]??null;
        if ($product===null) {
            return self::error(404,'product_not_found');
        }
        if ($version!==null&&$product['synthetic_version']!==$version) {
            return self::error(409,'stale_product_version');
        }
        if ($product['visible']||($product['synthetic_publish_pending']??false)) {
            return self::error(409,'product_publication_active');
        }
        if (!in_array(true,$fields,true)||array_diff(array_keys($fields),['title','description','images','variants','tags','keyFeatures','shipping_template'])
            ||count(array_filter($fields,'is_bool'))!==count($fields)) {
            return self::error(422,'product_publish_fields_invalid');
        }
        $this->products[$id]['synthetic_publish_pending']=true;
        $this->products[$id]['synthetic_publication_state']='PENDING';
        ++$this->products[$id]['synthetic_version'];
        return self::response(200,[]);
    }

    private function resolvePublication(string $id,string $operation,array $body,?int $version): array
    {
        $product=$this->availableProducts()[$id]??null;
        if ($product===null) {
            return self::error(404,'product_not_found');
        }
        if ($version!==null&&$product['synthetic_version']!==$version) {
            return self::error(409,'stale_product_version');
        }
        $pending=($product['synthetic_publish_pending']??false)===true;
        if ($operation==='PRODUCT_UNPUBLISH_NOTIFY') {
            if ($body!==[]) {
                return self::error(422,'product_unpublish_body_invalid');
            }
            if ($pending || $product['visible']!==true) {
                return self::error(409,'product_publication_inactive');
            }
            $state='UNPUBLISHED';
        } else {
            if (!$pending) {
                return self::error(409,'product_publication_inactive');
            }
            if ($operation==='PRODUCT_PUBLISH_SUCCEEDED') {
                $external=$body['external']??null;
                if (!is_array($external)||array_keys($body)!==['external']||count($external)!==2
                    ||!is_string($external['id']??null)||preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$external['id'])!==1
                    ||($external['handle']??null)!=='https://products.example.invalid/'.$external['id']) {
                    return self::error(422,'synthetic_publication_reference_invalid');
                }
                $state='PUBLISHED';
            } else {
                if (array_keys($body)!==['reason']||!in_array($body['reason'],['CHANNEL_REJECTED','CHANNEL_TIMEOUT','HUMAN_REJECTED'],true)) {
                    return self::error(422,'product_publication_reason_invalid');
                }
                $state='FAILED';
            }
        }
        $product['visible']=$state==='PUBLISHED';
        $product['synthetic_publish_pending']=false;
        $product['synthetic_publication_state']=$state;
        ++$product['synthetic_version'];
        $this->products[$id]=$product;
        return self::response(200,[]);
    }

    private function validProduct(array $row): bool
    {
        if (($row['blueprint_id']??null)!==42||($row['print_provider_id']??null)!==7
            ||!is_string($row['title']??null)||!is_string($row['description']??null)
            ||!is_array($row['variants']??null)||!array_is_list($row['variants'])||$row['variants']===[]
            ||!is_array($row['print_areas']??null)||!array_is_list($row['print_areas'])||$row['print_areas']===[]) {
            return false;
        }
        $catalog=array_column(self::VARIANTS,null,'id');
        $selected=[];
        foreach ($row['variants'] as $variant) {
            $id=$variant['id']??null;
            if (!is_int($id)||!isset($catalog[$id])||isset($selected[$id])
                ||!is_bool($variant['is_enabled']??null)||!is_int($variant['price']??null)
                ||$variant['price']<($variant['is_enabled']?1:0)||$variant['price']>100000000) {
                return false;
            }
            $selected[$id]=true;
        }
        $assigned=[];
        foreach ($row['print_areas'] as $area) {
            if (!is_array($area)||!is_array($area['variant_ids']??null)||!array_is_list($area['variant_ids'])
                ||$area['variant_ids']===[]||!is_array($area['placeholders']??null)
                ||!array_is_list($area['placeholders'])||$area['placeholders']===[]) {
                return false;
            }
            foreach ($area['variant_ids'] as $id) {
                if (!is_int($id)||!isset($selected[$id])||isset($assigned[$id])) {
                    return false;
                }
                $assigned[$id]=true;
            }
            $positions=[];
            foreach ($area['placeholders'] as $holder) {
                $position=$holder['position']??null;
                if (!is_string($position)||isset($positions[$position])||!is_array($holder['images']??null)
                    ||!array_is_list($holder['images'])||$holder['images']===[]) {
                    return false;
                }
                $positions[$position]=true;
                foreach ($area['variant_ids'] as $id) {
                    if (!in_array($position,array_column($catalog[$id]['placeholders'],'position'),true)) {
                        return false;
                    }
                }
                foreach ($holder['images'] as $image) {
                    $id=$image['id']??null;
                    if (!is_string($id)||!isset($this->images[$id])||($this->images[$id]['archived']??false)===true) {
                        return false;
                    }
                }
            }
        }
        return count($assigned)===count($selected);
    }

    private function orderLines(mixed $lines): ?array
    {
        if (!is_array($lines) || !array_is_list($lines) || $lines === [] || count($lines) > 50) {
            return null;
        }
        $copy = [];
        foreach ($lines as $line) {
            if (!is_array($line) || !is_string($line['product_id'] ?? null) || !is_int($line['variant_id'] ?? null)
                || !is_int($line['quantity'] ?? null) || $line['quantity'] < 1 || $line['quantity'] > 100
                || !isset($this->products[$line['product_id']])
                || ($this->products[$line['product_id']]['is_deleted']??false)===true) {
                return null;
            }
            $product = $this->products[$line['product_id']];
            $variants=array_column($product['variants'],null,'id');
            if (($variants[$line['variant_id']]['is_enabled']??null)!==true) {
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
        foreach ($lines as $index => &$line) {
            $line['id'] = 'line-'.($index+1);
        }
        unset($line);
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

    private function cancelLine(string $order, string $line): array
    {
        $record = $this->orders[$order] ?? null;
        if ($record === null) return self::error(404, 'order_not_found');
        if ($record['status'] !== 'on-hold') return self::error(409, 'order_not_cancelable');
        foreach ($record['line_items'] as $index=>$item) {
            if (($item['id'] ?? null) === $line && ($item['status'] ?? null) !== 'canceled') {
                $this->orders[$order]['line_items'][$index]['status'] = 'canceled';
                return self::response(204, []);
            }
        }
        return self::error(404, 'cancelable_line_not_found');
    }

    private function produce(string $id): array
    {
        if (($this->orders[$id]['status'] ?? null) !== 'on-hold' || !isset($this->productionConfirmations[$id])
            || !array_filter($this->orders[$id]['line_items'], static fn(array $line):bool=>($line['status']??null)!=='canceled')) {
            return self::error(409, 'production_simulation_not_confirmed');
        }
        unset($this->productionConfirmations[$id]);
        $this->orders[$id]['status'] = 'in-production';
        $this->orders[$id]['sent_to_production_at'] = '2026-11-19T00:00:00Z';
        return self::response(200, $this->orders[$id]);
    }

    public function fixtureDisconnected(): bool
    {
        return !$this->connected;
    }

    public function fixtureProductDeleted(string $id): bool
    {
        return ($this->products[$id]['is_deleted']??false)===true;
    }

    public function finishFixturePreview(string $taskId,string $status): bool
    {
        if (!in_array($status,['failed','corrupt'],true)) {
            return false;
        }
        foreach ($this->availableProducts() as $id=>$product) {
            if (($product['preview_tasks'][$taskId]['status']??null)==='pending') {
                $this->products[$id]['preview_tasks'][$taskId]['status']=$status;
                return true;
            }
        }
        return false;
    }

    private function personalization(string $operation,array $route,array $body): array
    {
        $id=$route[0];
        $product=$this->availableProducts()[$id]??null;
        if ($product===null) {
            return self::error(404,'personalization_product_not_found');
        }
        if ($operation==='PERSONALIZATION_OPTIONS') {
            return self::response(200,[['field_id'=>self::PERSONALIZATION_FIELD,'label'=>'Synthetic inscription',
                'type'=>'textbox','text_options'=>['character_limit'=>self::PERSONALIZATION_MAX_CHARS]],
                ['field_id'=>self::PERSONALIZATION_IMAGE,'label'=>'Synthetic artwork','type'=>'image']]);
        }
        if ($operation==='PREVIEW_READ') {
            $task=$product['preview_tasks'][$route[1]]??null;
            return $task===null?self::error(404,'preview_task_not_found'):self::response(200,$task);
        }
        if (array_key_exists('product_id',$body)) {
            return self::error(422,'personalization_body_scope');
        }
        PrintifyProductRequest::prepare($operation,$this->shop,['product_id'=>$id]+$body);
        $configure=$operation==='PERSONALIZATION_CONFIGURE';
        $items=$configure?$body['items']:$body['personalization'];
        foreach ($items as $item) {
            $valid=match ($item['field_id']) {
                self::PERSONALIZATION_FIELD => $item['type']==='textbox'
                    && mb_strlen($item['input']['text'],'UTF-8')<=self::PERSONALIZATION_MAX_CHARS,
                self::PERSONALIZATION_IMAGE => $item['type']==='image' && in_array($item['input']['image_url'],
                    array_column($this->availableImages(),'preview_url'),true),
                default => false,
            };
            if (!$valid) {
                return self::error(422,'personalization_field_invalid');
            }
        }
        $variants=array_column($product['variants'],null,'id');
        foreach ($configure?[$body['variant_id']]:$body['variant_ids'] as $variant) {
            if (($variants[$variant]['is_enabled']??null)!==true) {
                return self::error(422,'personalization_variant_unavailable');
            }
        }
        if ($configure) {
            $variant=$body['variant_id'];
            if (isset($product['personalization_configs'][$variant])) {
                return self::error(409,'personalization_config_already_exists');
            }
            $reference='fixture-config-'.hash('sha256',$id.':'.$variant);
            $this->products[$id]['personalization_configs'][$variant]=$reference;
            return self::response(201,['personalisation_strategy'=>'pstudio_pre_order_headless',
                'personalisation_instructions'=>$reference,'warnings'=>[]]);
        }
        $tasks=$product['preview_tasks']??[];
        if (count($tasks)>=24) {
            return self::error(429,'preview_task_limit');
        }
        $correlation='fixture-preview-'.hash('sha256',$body['external_id']);
        foreach ($tasks as $entry) {
            if ($entry['external_id']===$correlation) {
                return self::error(409,'preview_task_already_exists');
            }
        }
        $taskId='task-'.$id.'-'.(count($tasks)+1);
        $task=['task_id'=>$taskId,'status'=>'pending','external_id'=>$correlation];
        $this->products[$id]['preview_tasks'][$taskId]=$task;
        return self::response(202,$task);
    }

    private function changeOrderAddress(string $orderId,array $body): array
    {
        $order=$this->orders[$orderId]??null;
        if ($order===null) {
            return self::error(404,'address_order_not_found');
        }
        if ($order['status']!=='on-hold') {
            return self::error(409,'address_order_ineligible');
        }
        if (array_key_exists('order_id',$body)) {
            return self::error(422,'address_body_scope');
        }
        $validated=PrintifySupportContract::prepare('SUPPORT_ADDRESS_CHANGE',$this->shop,['order_id'=>$orderId]+$body);
        $hash=hash('sha256',Validator::canonicalJson($validated['body']));
        $history=$order['address_change_hashes']??[];
        if (in_array($hash,$history,true)) {
            return self::error(409,'address_change_already_observed');
        }
        if (count($history)>=12) {
            return self::error(429,'address_change_limit');
        }
        $this->orders[$orderId]['address_change_hashes'][]=$hash;
        return self::response(200,['resolution'=>'updated_directly','order_id'=>$orderId,'address_to'=>$validated['body']]);
    }

    private function support(string $operation,array $route,array $body): array
    {
        $orderId=$route[0];
        $order=$this->orders[$orderId]??null;
        if ($order===null) {
            return self::error(404,'support_order_not_found');
        }
        $requests=$order['support_requests']??[];
        if ($operation==='SUPPORT_LIST') {
            return self::response(200,['data'=>array_values($requests)]);
        }
        if ($operation==='SUPPORT_DETAIL') {
            $entry=$requests[$route[1]]??null;
            return $entry===null?self::error(404,'support_request_not_found'):self::response(200,['data'=>$entry]);
        }
        if (count($requests)>=24) {
            return self::error(429,'support_request_limit');
        }
        if (array_key_exists('order_id',$body)) {
            return self::error(422,'support_body_scope');
        }
        $input=['order_id'=>$orderId]+$body;
        PrintifySupportContract::prepare($operation,$this->shop,$input);
        $address=$operation==='SUPPORT_ADDRESS_CHANGE';
        $eligible=$address?in_array($order['status'],['on-hold','in-production'],true):$order['status']==='fulfilled';
        if (!$eligible) {
            return self::error(409,'support_order_ineligible');
        }
        if (!$address) {
            $order['line_items']=array_values(array_filter($order['line_items'],
                static fn(array $line): bool=>($line['status']??null)!=='canceled'));
            PrintifySupportContract::validateEligibility($operation,$input,$order);
        }
        $lineIds=$address?[]:array_column($body['line_items'],'line_item_id');
        $type=match($operation){
            'SUPPORT_REFUND'=>'order_refund',
            'SUPPORT_REPRINT'=>'order_reprint',
            default=>'order_address_editing',
        };
        foreach ($requests as $prior) {
            if ($prior['type']===$type&&($address||array_intersect($lineIds,$prior['line_item_ids']))) {
                return self::error(409,'support_request_already_pending');
            }
        }
        $id='support-'.$orderId.'-'.(count($requests)+1);
        $entry=['id'=>$id,'order_id'=>$orderId,'type'=>$type,'status'=>'pending','outcome'=>'unresolved',
            'line_item_ids'=>$lineIds,'created_at'=>'2026-11-19T00:00:00Z','updated_at'=>'2026-11-19T00:00:00Z'];
        $this->orders[$orderId]['support_requests'][$id]=$entry;
        return self::response(201,$address?$entry+['resolution'=>'support_request_created']:$entry);
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
