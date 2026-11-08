<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Official API request contracts. Descriptions convey no network authority. */
final class PrintifyApiRequest
{
    public static function prepare(string $operation, string $shop, array $input = []): array
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $shop)) {
            throw new \InvalidArgumentException('Exact provider shop identity required.');
        }
        $keys = match ($operation) {
            'SHOPS_READ','PROVIDERS_READ','WEBHOOKS_READ' => [],
            'PROVIDER_READ' => ['provider_id'],
            'PRODUCTS_READ','ORDERS_READ' => ['page','limit'],
            'ORDER_READ' => ['order_id'],
            'PRODUCT_PUBLISH' => ['product_id','title','description','images','variants','tags','keyFeatures','shipping_template'],
            default => null,
        };
        if ($keys !== null && array_diff(array_keys($input), $keys)) { throw new \InvalidArgumentException('Unsupported request input.'); }
        $root = '/v1/shops/' . $shop;
        $body = [];
        $method = 'GET';
        switch ($operation) {
            case 'SHOPS_READ': $endpoint = '/v1/shops.json'; $scope = 'shops.read'; break;
            case 'PROVIDERS_READ': $endpoint = '/v1/catalog/print_providers.json'; $scope = 'print_providers.read'; break;
            case 'PROVIDER_READ': $endpoint = '/v1/catalog/print_providers/' . self::positive($input['provider_id'] ?? null) . '.json'; $scope = 'print_providers.read'; break;
            case 'PRODUCTS_READ':
            case 'ORDERS_READ':
                $orders = $operation === 'ORDERS_READ';
                $page = self::positive($input['page'] ?? 1);
                $limit = self::positive($input['limit'] ?? 10);
                if ((int)$limit > 10) { throw new \InvalidArgumentException('Bounded pagination required.'); }
                $endpoint = $root . ($orders ? '/orders.json' : '/products.json') . '?page=' . $page . '&limit=' . $limit;
                $scope = $orders ? 'orders.read' : 'products.read';
                break;
            case 'ORDER_READ': $endpoint = $root . '/orders/' . self::id($input['order_id'] ?? null) . '.json'; $scope = 'orders.read'; break;
            case 'WEBHOOKS_READ': $endpoint = $root . '/webhooks.json'; $scope = 'webhooks.read'; break;
            case 'PRODUCT_PUBLISH':
                $endpoint = $root . '/products/' . self::id($input['product_id'] ?? null) . '/publish.json';
                $method = 'POST'; $scope = 'products.write';
                foreach (['title','description','images','variants','tags','keyFeatures','shipping_template'] as $field) {
                    if (isset($input[$field])) {
                        if (!is_bool($input[$field])) { throw new \InvalidArgumentException('Explicit publish selections required.'); }
                        $body[$field] = $input[$field];
                    }
                }
                if (!in_array(true, $body, true)) { throw new \InvalidArgumentException('Empty publication selection.'); }
                break;
            default:
                // Existing image, product and personalization contracts retain their validation.
                return PrintifyProductRequest::prepare($operation, $shop, $input);
        }
        $material = ['operation'=>$operation,'provider_shop_id'=>$shop,'method'=>$method,'endpoint'=>$endpoint,'body'=>$body,'required_scope'=>$scope];
        return $material + ['fingerprint'=>hash('sha256', json_encode($material, JSON_THROW_ON_ERROR)), 'external_execution_authorized'=>false];
    }

    public static function mapping(string $shop, string $externalShop, array $otherMappings): array
    {
        if (!in_array($shop, ['personalized_pod','standard_pod'], true)) { throw new \InvalidArgumentException('Unsupported DigiForge shop.'); }
        self::positive($externalShop);
        foreach ($otherMappings as $key => $value) {
            if ($key !== $shop && (string)$value === $externalShop) { throw new \InvalidArgumentException('Provider shop already assigned to another DigiForge shop.'); }
        }
        return ['shop_key'=>$shop,'provider'=>'printify','provider_shop_id'=>$externalShop,'execution_authorized'=>false];
    }

    private static function positive(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,18}$/D', (string)$value)) { throw new \InvalidArgumentException('Positive identifier required.'); }
        return (string)$value;
    }
    private static function id(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{1,160}$/D', $value)) { throw new \InvalidArgumentException('Bounded resource identity required.'); }
        return $value;
    }
}
