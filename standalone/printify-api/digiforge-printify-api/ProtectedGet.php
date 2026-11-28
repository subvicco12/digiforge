<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

use DigiForgePrintifyApi\Engine\Listings\Validator;
use DigiForgePrintifyApi\Engine\PrintifyCatalogReadContract;
use DigiForgePrintifyApi\Engine\POD\{PrintifyApiReadEvidence, PrintifyApiRequest, PrintifySupportContract};

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__.'/Engine/Listings/Validator.php';
require_once __DIR__.'/Engine/PrintifyCatalogReadContract.php';
require_once __DIR__.'/Engine/POD/PrintifyApiRequest.php';
require_once __DIR__.'/Engine/POD/PrintifyApiReadEvidence.php';
require_once __DIR__.'/Engine/POD/PrintifyOrderEvidence.php';
require_once __DIR__.'/Engine/POD/PrintifySupportContract.php';

/** Shared GET intent and response contract. The WordPress package has no gateway transport. */
final class ProtectedGet
{
    public const VERSION = 'DF_STANDALONE_GET_1';
    public const REPLY_VERSION = 'DF_STANDALONE_GET_REPLY_1';
    public const MAX_PAGE = 40;

    public const OPERATOR_FIELDS = [
        'SHOPS_READ'=>[], 'BLUEPRINTS_READ'=>[], 'BLUEPRINT_READ'=>['blueprint_id'], 'BLUEPRINT_SIZE_GUIDE_READ'=>['blueprint_id'],
        'BLUEPRINT_PROVIDERS_READ'=>['blueprint_id'], 'BLUEPRINT_PROVIDER_READ'=>['blueprint_id','provider_id'], 'PROVIDERS_READ'=>[], 'PROVIDER_READ'=>['provider_id'],
        'VARIANTS_READ'=>['blueprint_id','provider_id','show-out-of-stock'], 'VARIANT_READ'=>['blueprint_id','provider_id','variant_id','show-out-of-stock'],
        'CATALOG_SHIPPING_READ'=>['blueprint_id','provider_id'],
        'CATALOG_SHIPPING_METHOD_READ'=>['blueprint_id','provider_id','method'],
        'PRODUCTS_READ'=>['page','limit'],'PRODUCT_READ'=>['product_id','blueprint_id','provider_id','variant_ids'],
        'PRODUCT_GPSR_READ'=>['product_id','blueprint_id','provider_id','variant_ids'],
        'PRODUCT_ARTWORK_READ'=>['product_id','blueprint_id','provider_id','variant_ids','image_id'],
        'MOCKUP_SELECTION_READ'=>['product_id'], 'LINKED_IMAGE_METADATA_READ'=>['product_id','image_id'],
        'PRODUCT_SKU_READ'=>['product_id','blueprint_id','provider_id','sku'],
        'ORDERS_READ'=>['page','limit','status','sku'],'ORDER_READ'=>['order_id'],
        'SUPPORT_LIST'=>['order_id'], 'SUPPORT_DETAIL'=>['order_id','support_request_id'],
    ];

    private const CATALOG = [
        'BLUEPRINTS_READ'=>'blueprints','BLUEPRINT_READ'=>'blueprint','BLUEPRINT_PROVIDERS_READ'=>'providers',
        'BLUEPRINT_SIZE_GUIDE_READ'=>'size_guide','BLUEPRINT_PROVIDER_READ'=>'provider','VARIANTS_READ'=>'variants','VARIANT_READ'=>'variant',
        'CATALOG_SHIPPING_READ'=>'shipping','CATALOG_SHIPPING_METHOD_READ'=>'shipping_method',
    ];
    public static function request(array $scope, string $operation, array $selection): array
    {
        $fields = ['site','actor','session','revision','account','shop','integration','custody'];
        if (count($scope) !== count($fields) || array_diff($fields, array_keys($scope)) !== []
            || !is_int($scope['actor']) || $scope['actor'] < 1) {
            throw new \InvalidArgumentException('Invalid read scope.');
        }
        foreach (['site','account','integration'] as $key) {
            self::requirePattern($scope[$key], '/^[a-zA-Z0-9_-]{1,100}$/D');
        }
        self::requirePattern($scope['shop'], '/^[1-9][0-9]{0,18}$/D');
        self::requirePattern($scope['custody'], '/^custody-ref:[a-zA-Z0-9_-]{1,88}$/D');
        foreach (['session','revision'] as $key) {
            self::requirePattern($scope[$key], '/^[a-f0-9]{64}$/D');
        }

        $fields = self::OPERATOR_FIELDS[$operation] ?? null;
        if ($fields === null) {
            throw new \InvalidArgumentException('Unsupported read operation.');
        }
        if (array_diff(array_keys($selection), $fields) !== []) {
            throw new \InvalidArgumentException('Unexpected read selection field.');
        }

        if (isset(self::CATALOG[$operation])) {
            $path = PrintifyCatalogReadContract::endpoint(['kind'=>self::CATALOG[$operation]] + array_diff_key($selection,['show-out-of-stock'=>true]));
            if (array_key_exists('show-out-of-stock',$selection)) {
                if (!in_array($selection['show-out-of-stock'],['0','1'],true)) throw new \InvalidArgumentException('Invalid availability selection.');
                $path.='?show-out-of-stock='.$selection['show-out-of-stock'];
            }
        } elseif (in_array($operation,['SUPPORT_LIST','SUPPORT_DETAIL'],true)) {
            $path = PrintifySupportContract::prepare($operation,$scope['shop'],$selection)['endpoint'];
        } elseif ($operation==='LINKED_IMAGE_METADATA_READ') {
            self::requirePattern($selection['product_id']??null,'/^[A-Za-z0-9_-]{1,160}$/D');
            self::requirePattern($selection['image_id']??null,'/^[A-Za-z0-9_-]{1,160}$/D');
            $path='/v1/uploads/'.$selection['image_id'].'.json';
        } elseif ($operation === 'MOCKUP_SELECTION_READ') {
            self::requirePattern($selection['product_id']??null,'/^[A-Za-z0-9_-]{1,160}$/D');
            $path='/v1/shops/'.$scope['shop'].'/products/'.$selection['product_id'].'/mockup-selection.json';
        } elseif ($operation === 'PRODUCT_ARTWORK_READ') {
            self::requirePattern($selection['image_id'] ?? null, '/^[A-Za-z0-9_-]{1,160}$/D');
            $path = PrintifyApiRequest::prepare('PRODUCT_READ', $scope['shop'],
                array_diff_key($selection, ['image_id'=>true]))['endpoint'];
        } elseif ($operation === 'PRODUCT_SKU_READ') {
            if (count($selection) !== 4 || array_diff(['product_id','blueprint_id','provider_id','sku'], array_keys($selection))) {
                throw new \InvalidArgumentException('Exact product and SKU selection required.');
            }
            self::requirePattern($selection['product_id'], '/^[A-Za-z0-9_-]{1,160}$/D');
            self::requirePattern($selection['sku'], '/^[A-Za-z0-9][A-Za-z0-9._-]{0,159}$/D');
            foreach (['blueprint_id','provider_id'] as $key) {
                if (!is_int($selection[$key]) || $selection[$key]<1 || $selection[$key]>2147483647) {
                    throw new \InvalidArgumentException('Invalid catalog selection.');
                }
            }
            $path = '/v1/shops/'.$scope['shop'].'/products/'.$selection['product_id'].'.json';
        } else {
            if (in_array($operation, ['PRODUCTS_READ','ORDERS_READ'], true)) {
                $selection += ['page'=>1,'limit'=>10];
                if (!is_int($selection['page']) || $selection['page'] < 1 || $selection['page'] > self::MAX_PAGE
                    || !is_int($selection['limit']) || $selection['limit'] < 1 || $selection['limit'] > 10) {
                    throw new \InvalidArgumentException('Page is outside the read window.');
                }
            }
            $filters=[];
            if ($operation==='ORDERS_READ') {
                foreach (['status'=>'/^[a-z][a-z0-9-]{0,59}$/D','sku'=>'/^[A-Za-z0-9][A-Za-z0-9._-]{0,159}$/D'] as $key=>$rule) {
                    if (!array_key_exists($key,$selection)) continue;
                    self::requirePattern($selection[$key],$rule);
                    $filters[$key]=$selection[$key];
                }
            }
            $path = PrintifyApiRequest::prepare($operation, $scope['shop'], array_diff_key($selection,$filters))['endpoint'];
            foreach ($filters as $key=>$value) $path.='&'.$key.'='.rawurlencode($value);
        }
        return ['version'=>self::VERSION,'method'=>'GET','scope'=>$scope,'operation'=>$operation,
            'selection'=>$selection,'path'=>$path];
    }

    public static function digest(array $value): string
    {
        return hash('sha256', Validator::canonicalJson($value));
    }

    public static function project(array $request, string $body): array
    {
        if (strlen($body) > 262144) {
            throw new \InvalidArgumentException('Response too large.');
        }
        $data = json_decode($body, true, 24, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Expected structured evidence.');
        }
        $operation = $request['operation'] ?? null;
        $selection = $request['selection'] ?? null;
        $scope = $request['scope'] ?? null;
        if (!is_string($operation) || !is_array($selection) || !is_array($scope)
            || self::digest($request) !== self::digest(self::request($scope, $operation, $selection))) {
            throw new \InvalidArgumentException('Read request binding mismatch.');
        }
        $shop = $scope['shop'];
        if (in_array($operation,['SUPPORT_LIST','SUPPORT_DETAIL'],true)) {
            if (array_key_exists('shop_id',$data) && !self::matchesShop($data['shop_id'],$shop)) {
                throw new \InvalidArgumentException('Support response scope mismatch.');
            }
            $items = $operation === 'SUPPORT_DETAIL' ? [$data['data'] ?? null] : ($data['data'] ?? []);
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (is_array($item) && array_key_exists('shop_id',$item) && !self::matchesShop($item['shop_id'],$shop)) {
                        throw new \InvalidArgumentException('Support item scope mismatch.');
                    }
                }
            }
            $contract = PrintifySupportContract::prepare($operation,$shop,$selection);
            return self::checkedSupportProjection(PrintifySupportContract::project($contract,$data,200));
        }
        if ($operation==='LINKED_IMAGE_METADATA_READ') {
            if (($data['id']??null)!==$selection['image_id']
                || (isset($data['shop_id']) && (!is_int($data['shop_id']) || (string)$data['shop_id']!==$shop))) {
                throw new \InvalidArgumentException('Linked asset identity mismatch.');
            }
            $metadata=['product_id'=>$selection['product_id'],'image_id'=>$selection['image_id']];
            foreach (['width'=>100000,'height'=>100000,'size'=>1000000000] as $field=>$maximum) {
                $value=$data[$field]??null;
                if ($value!==null && (!is_int($value) || $value<1 || $value>$maximum)) throw new \InvalidArgumentException('Invalid linked asset measurement.');
                $metadata[$field]=$value;
            }
            $mime=$data['mime_type']??null;
            if ($mime!==null) self::requirePattern($mime,'~^image/[a-z0-9.+-]{1,40}$~D');
            $metadata['mime_type']=$mime;
            return $metadata;
        }
        if ($operation==='MOCKUP_SELECTION_READ') {
            if ($request!==self::request($request['scope'],$operation,$selection)) {
                throw new \InvalidArgumentException('Mockup request binding mismatch.');
            }
            return ['shop_id'=>$shop,'product_id'=>$selection['product_id'],'mockups'=>self::mockupRows($data)];
        }
        if (isset(self::CATALOG[$operation])) {
            $catalog = PrintifyCatalogReadContract::select($data, ['kind'=>self::CATALOG[$operation]] + array_diff_key($selection,['show-out-of-stock'=>true]));
            $summary = static fn(array $row): array => ['id'=>$row['id'],'title'=>$row['title'] ?? null];
            return match ($operation) {
                'BLUEPRINTS_READ' => array_map($summary, $catalog),
                'BLUEPRINT_PROVIDERS_READ' => array_map(static fn(array $row): array =>
                    $summary($row) + ['blueprint_id'=>$selection['blueprint_id']], $catalog),
                'BLUEPRINT_READ' => $summary($catalog),
                'BLUEPRINT_PROVIDER_READ' => $summary($catalog) + ['blueprint_id'=>$selection['blueprint_id'],
                    'decoration_methods'=>$catalog['decoration_methods'],'production_time'=>$catalog['production_time']],
                'BLUEPRINT_SIZE_GUIDE_READ' => ['blueprint_id'=>$selection['blueprint_id']] + $catalog,
                'VARIANTS_READ' => ['blueprint_id'=>$selection['blueprint_id'],'provider_id'=>$selection['provider_id'],
                    'variants'=>array_map(self::variant(...), $catalog['variants'])],
                'VARIANT_READ' => self::variant($catalog) + ['blueprint_id'=>$selection['blueprint_id'],
                    'provider_id'=>$selection['provider_id'],'print_areas'=>self::variantAreas($catalog)],
                'CATALOG_SHIPPING_READ' => ['blueprint_id'=>$selection['blueprint_id'],
                    'provider_id'=>$selection['provider_id'],'methods'=>array_map(
                    static fn(array $row): string => $row['attributes']['name'], $catalog['data']), 'address_quote'=>false],
                'CATALOG_SHIPPING_METHOD_READ' => ['blueprint_id'=>$selection['blueprint_id'],
                    'provider_id'=>$selection['provider_id'],'rates'=>array_map(
                    static fn(array $row): array => [
                        'variant_id'=>$row['attributes']['variantId'],
                        'method'=>$row['attributes']['shippingType'],
                        'country'=>$row['attributes']['country']['code'],
                        'first_item'=>$row['attributes']['shippingCost']['firstItem']['amount'],
                        'additional_item'=>$row['attributes']['shippingCost']['additionalItems']['amount'],
                        'currency'=>$row['attributes']['shippingCost']['firstItem']['currency'],
                        'shipping_plan_id'=>$row['attributes']['shippingPlanId'],
                        'handling_time'=>array_intersect_key($row['attributes']['handlingTime'], ['from'=>true,'to'=>true]),
                    ], $catalog['data']), 'address_quote'=>false],
            };
        }
        if (in_array($operation, ['PRODUCTS_READ','ORDERS_READ','PRODUCT_READ','ORDER_READ'], true)) {
            $rows = in_array($operation, ['PRODUCTS_READ','ORDERS_READ'], true) ? ($data['data'] ?? null) : [$data];
            if (!is_array($rows)) {
                throw new \InvalidArgumentException('Missing records.');
            }
            if (array_key_exists('shop_id', $data) && !self::matchesShop($data['shop_id'], $shop)) {
                throw new \InvalidArgumentException('Response envelope scope mismatch.');
            }
            foreach ($rows as $row) {
                if (!is_array($row) || (array_key_exists('shop_id', $row)
                    && !self::matchesShop($row['shop_id'], $shop))) {
                    throw new \InvalidArgumentException('Response scope mismatch.');
                }
                if ($operation==='ORDERS_READ') {
                    if (isset($selection['status']) && ($row['status']??null)!==$selection['status']) {
                        throw new \InvalidArgumentException('Order status filter mismatch.');
                    }
                    if (isset($selection['sku']) && (!is_array($row['line_items']??null)
                        || !in_array($selection['sku'],array_column($row['line_items'],'sku'),true))) {
                        throw new \InvalidArgumentException('Order SKU filter unavailable or mismatched.');
                    }
                }
            }
        }
        if ($operation === 'PRODUCT_SKU_READ') {
            return self::productSku($data,$shop,$selection);
        }
        if ($operation === 'PRODUCT_ARTWORK_READ') {
            $product = PrintifyApiReadEvidence::project('PRODUCT_READ', $data, $shop,
                array_diff_key($selection, ['image_id'=>true]));
            self::attachArtworkMetadata($product, $data);
            return self::linkedArtwork($product,$selection['image_id']);
        }
        $providerSelection=$operation==='ORDERS_READ'?array_diff_key($selection,['status'=>true,'sku'=>true]):$selection;
        $projection = PrintifyApiReadEvidence::project($operation, $data, $shop, $providerSelection);
        if ($operation==='ORDERS_READ') {
            $total = $data['total'] ?? null;
            if (array_key_exists('total',$data) && (!is_int($total) || $total < count($projection['data'])
                || $total > 2147483647)) {
                throw new \InvalidArgumentException('Invalid order pagination total.');
            }
            $filters=array_intersect_key($selection,['status'=>true,'sku'=>true]);
            if ($filters!==[]) $projection['filters']=$filters;
        }
        if ($operation === 'PRODUCT_READ') {
            self::attachArtworkMetadata($projection, $data);
        }
        if (($projection['next_page']['page'] ?? 0) > self::MAX_PAGE) {
            $projection['next_page'] = null;
            $projection['page_window_exhausted'] = true;
        }
        return $projection;
    }

    /** The shared support parser supplies only typed, redacted data. Fence calendar rollover and time ordering. */
    public static function checkedSupportProjection(array $projection): array
    {
        $items = $projection['support_requests'] ?? (isset($projection['support_request']) ? [$projection['support_request']] : []);
        foreach ($items as $item) {
            foreach (['created_at','updated_at'] as $field) {
                $parsed = date_parse($item[$field]);
                if ($parsed['warning_count'] !== 0 || $parsed['error_count'] !== 0) {
                    throw new \InvalidArgumentException('Invalid support observation calendar date.');
                }
            }
            if (new \DateTimeImmutable($item['updated_at']) < new \DateTimeImmutable($item['created_at'])) {
                throw new \InvalidArgumentException('Support observation predates creation.');
            }
        }
        return $projection;
    }

    private static function matchesShop(mixed $value, string $shop): bool
    {
        return (is_int($value) || is_string($value)) && (string)$value === $shop;
    }

    /** An account media-library ID is not ownership evidence. Require current product placement. */
    private static function linkedArtwork(array $product,string $id): array
    {
        $placements = [];
        foreach ($product['print_areas'] as $area) {
            foreach ($area['placeholders'] as $position) {
                foreach ($position['images'] as $image) {
                    if ($image['id'] === $id) {
                        $placements[] = ['variant_ids'=>$area['variant_ids'],'position'=>$position['position'],
                            'artwork'=>$image];
                    }
                }
            }
        }
        if (!$placements) {
            throw new \InvalidArgumentException('Artwork is not linked to the selected product.');
        }
        return ['product_id'=>$product['id'],'blueprint_id'=>$product['blueprint_id'],
            'provider_id'=>$product['print_provider_id'],'image_id'=>$id,'placements'=>$placements];
    }

    /** Resolve one enabled SKU within an already bound product, never across a shop. */
    private static function productSku(array $data,string $shop,array $selection): array
    {
        $variants = $data['variants'] ?? null;
        if (!is_array($variants) || !array_is_list($variants) || count($variants)>1000) {
            throw new \InvalidArgumentException('Missing or unbounded product variants.');
        }
        $ids = array_column($variants,'id');
        $product = PrintifyApiReadEvidence::project('PRODUCT_READ',$data,$shop,[
            'product_id'=>$selection['product_id'],'blueprint_id'=>$selection['blueprint_id'],
            'provider_id'=>$selection['provider_id'],'variant_ids'=>$ids,
        ]);
        $matches = array_values(array_filter($variants, static fn(array $variant): bool =>
            ($variant['sku'] ?? null) === $selection['sku']));
        if (count($matches)!==1 || $matches[0]['is_enabled']!==true) {
            throw new \InvalidArgumentException('Exact enabled SKU absent or ambiguous.');
        }
        $id = $matches[0]['id'];
        self::attachArtworkMetadata($product,$data);
        $areas = array_values(array_filter($product['print_areas'],
            static fn(array $area): bool => in_array($id,$area['variant_ids'],true)));
        foreach ($areas as &$area) {
            $area['variant_ids'] = [$id];
        }
        return ['id'=>$product['id'],'blueprint_id'=>$product['blueprint_id'],'print_provider_id'=>$product['print_provider_id'],
            'sku'=>$selection['sku'],'variant_id'=>$id,'print_areas'=>$areas,
            'supplier_certified'=>false];
    }

    private static function variantAreas(array $variant): ?array
    {
        $areas = PrintifyCatalogReadContract::variantAreas($variant);
        if ($areas === null) return null;
        foreach ($areas as $index=>&$area) {
            $method = $variant['placeholders'][$index]['decoration_method'] ?? null;
            $area['decoration_method'] = is_string($method) && preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$method)
                ? $method : null;
        }
        return $areas;
    }

    /**
     * Match the source placements by the same indexes preserved by the validated product projection.
     * Artwork references are the existing image IDs. Source URLs can carry private download grants.
     */
    private static function attachArtworkMetadata(array &$product, array $source): void
    {
        foreach ($product['print_areas'] as $areaIndex => &$area) {
            foreach ($area['placeholders'] as $positionIndex => &$position) {
                foreach ($position['images'] as $imageIndex => &$image) {
                    $raw = $source['print_areas'][$areaIndex]['placeholders'][$positionIndex]['images'][$imageIndex];
                    $name = $raw['name'] ?? null;
                    $type = $raw['type'] ?? null;
                    $image['metadata'] = [
                        'name'=>is_string($name) && trim($name) !== '' && strlen($name) <= 240
                            && !preg_match('~[<>/\x00-\x1f\x7f]|[\\\\:]~', $name) ? $name : null,
                        'type'=>is_string($type) && preg_match('~^image/[a-zA-Z0-9][a-zA-Z0-9.+-]{0,59}$~D', $type) ? $type : null,
                    ];
                    foreach (['width', 'height'] as $dimension) {
                        $value = $raw[$dimension] ?? null;
                        $image['metadata'][$dimension] = is_int($value) && $value > 0 && $value <= 2147483647
                            ? $value : null;
                    }
                }
                unset($image);
            }
            unset($position);
        }
    }

    /** Typed rows from the exact scoped GET. Source URLs are validated and discarded. */
    public static function mockupRows(array $rows): array
    {
        if (!array_is_list($rows) || count($rows)>200) throw new \InvalidArgumentException('Invalid mockup response.');
        $output=[];
        $seen=[];
        foreach ($rows as $row) {
            if (!is_array($row) || count($row)!==5 || !is_bool($row['selected']??null)
                || !in_array($row['type']??null,['GENERATED','UPLOADED'],true)
                || !is_array($row['variant_ids']??null) || !array_is_list($row['variant_ids'])
                || count($row['variant_ids'])>1000 || ($row['type']==='GENERATED' && $row['variant_ids']===[])) {
                throw new \InvalidArgumentException('Invalid mockup row.');
            }
            $id=$row['mockup_id']??null;
            self::requirePattern($id,'/^[A-Za-z0-9_-]{1,200}$/D');
            if (isset($seen[$id])) throw new \InvalidArgumentException('Duplicate mockup identity.');
            $seen[$id]=true;
            $variants=[];
            foreach ($row['variant_ids'] as $variant) {
                if (!is_int($variant) || $variant<1 || $variant>2147483647 || isset($variants[$variant])) {
                    throw new \InvalidArgumentException('Invalid mockup variant reference.');
                }
                $variants[$variant]=true;
            }
            $url=$row['src']??null;
            $parts=is_string($url) && strlen($url)<=2048 && !preg_match('~[\x00-\x20\x7f<>\\\\]~',$url)?parse_url($url):false;
            if (!is_array($parts) || ($parts['scheme']??null)!=='https'
                || !preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$/D',$parts['host']??'')
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || !str_starts_with($parts['path']??'','/')) {
                throw new \InvalidArgumentException('Unsafe mockup URL.');
            }
            $output[]=['mockup_id'=>$id,'type'=>$row['type'],'variant_ids'=>$row['variant_ids'],'selected'=>$row['selected']];
        }
        return $output;
    }

    private static function variant(array $row): array
    {
        $options = $row['options'] ?? null;
        if ($options !== null) {
            if (!is_array($options) || count($options) > 3) {
                throw new \InvalidArgumentException('Invalid catalog variant options.');
            }
            foreach ($options as $name => $value) {
                if (!is_string($name) || !preg_match('/^[a-zA-Z][a-zA-Z0-9 _-]{0,59}$/D', $name)
                    || !is_string($value) || trim($value) === '' || strlen($value) > 160
                    || preg_match('/[\x00-\x1f\x7f<>]/', $value)) {
                    throw new \InvalidArgumentException('Invalid catalog variant option.');
                }
            }
        }
        return ['id'=>$row['id'],'title'=>$row['title'] ?? null,'options'=>$options === [] ? null : $options]
            + array_intersect_key($row,['is_available'=>true]);
    }

    public static function verifyReply(array $request, string $grantId, array $reply, string $key, int $now): array
    {
        $invalid = ['state'=>'UNAVAILABLE','reason'=>'REPLY_UNVERIFIED'] + self::denied();
        try {
            $receipt = $reply['receipt'] ?? null;
            $signature = $reply['signature'] ?? null;
            if (count($reply) !== 2 || !is_array($receipt) || !is_string($signature)
                || !preg_match('/^[a-f0-9]{128}$/D', $signature)
                || ($receipt['schema'] ?? null) !== self::REPLY_VERSION
                || ($receipt['request_digest'] ?? null) !== self::digest($request)
                || ($receipt['grant_id'] ?? null) !== $grantId
                || !is_int($receipt['issued_at'] ?? null) || $receipt['issued_at'] > $now
                || $receipt['issued_at'] < $now - 30
                || !in_array($receipt['state'] ?? null, ['OBSERVED','DENIED','UNAVAILABLE'], true)
                || ($receipt['retry_permitted'] ?? null) !== false
                || ($receipt['provider_execution_authorized'] ?? null) !== false
                || !sodium_crypto_sign_verify_detached(hex2bin($signature), self::digest($receipt), $key)) {
                return $invalid;
            }
            if ($receipt['state'] === 'OBSERVED'
                && (($receipt['source'] ?? null) !== 'SYNTHETIC_PRINTIFY' || !is_array($receipt['data'] ?? null)
                    || ($receipt['observed_at'] ?? null) !== $receipt['issued_at']
                    || !is_string($receipt['response_sha256'] ?? null)
                    || !preg_match('/^[a-f0-9]{64}$/D', $receipt['response_sha256']))) {
                return $invalid;
            }
            return $receipt;
        } catch (\Throwable) {
            return $invalid;
        }
    }

    public static function denied(): array
    {
        return ['state'=>'BLOCKED','reason'=>'GATEWAY_UNQUALIFIED','source'=>null,'observed_at'=>null,
            'provider_execution_authorized'=>false,'retry_permitted'=>false];
    }

    private static function requirePattern(mixed $value, string $pattern): void
    {
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new \InvalidArgumentException('Invalid read identity.');
        }
    }
}
