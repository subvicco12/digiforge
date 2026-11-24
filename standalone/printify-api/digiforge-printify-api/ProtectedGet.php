<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

use DigiForgePrintifyApi\Engine\Listings\Validator;
use DigiForgePrintifyApi\Engine\PrintifyCatalogReadContract;
use DigiForgePrintifyApi\Engine\POD\{PrintifyApiReadEvidence, PrintifyApiRequest};

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__.'/Engine/Listings/Validator.php';
require_once __DIR__.'/Engine/PrintifyCatalogReadContract.php';
require_once __DIR__.'/Engine/POD/PrintifyApiRequest.php';
require_once __DIR__.'/Engine/POD/PrintifyApiReadEvidence.php';
require_once __DIR__.'/Engine/POD/PrintifyOrderEvidence.php';

/** Shared GET intent and response contract. The WordPress package has no gateway transport. */
final class ProtectedGet
{
    public const VERSION = 'DF_STANDALONE_GET_1';
    public const REPLY_VERSION = 'DF_STANDALONE_GET_REPLY_1';
    public const MAX_PAGE = 40;

    private const CATALOG = [
        'BLUEPRINTS_READ'=>'blueprints','BLUEPRINT_READ'=>'blueprint','BLUEPRINT_PROVIDERS_READ'=>'providers',
        'BLUEPRINT_PROVIDER_READ'=>'provider','VARIANTS_READ'=>'variants','VARIANT_READ'=>'variant',
        'CATALOG_SHIPPING_READ'=>'shipping','CATALOG_SHIPPING_METHOD_READ'=>'shipping_method',
    ];
    private const RECORDS = [
        'SHOPS_READ','PROVIDERS_READ','PROVIDER_READ','PRODUCTS_READ','PRODUCT_READ','ORDERS_READ','ORDER_READ',
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

        if (isset(self::CATALOG[$operation])) {
            if (array_key_exists('kind', $selection)) {
                throw new \InvalidArgumentException('Catalog kind is selected by operation.');
            }
            $path = PrintifyCatalogReadContract::endpoint(['kind'=>self::CATALOG[$operation]] + $selection);
        } elseif (in_array($operation, self::RECORDS, true)) {
            if (in_array($operation, ['PRODUCTS_READ','ORDERS_READ'], true)) {
                $selection += ['page'=>1,'limit'=>10];
                if (!is_int($selection['page']) || $selection['page'] < 1 || $selection['page'] > self::MAX_PAGE
                    || !is_int($selection['limit']) || $selection['limit'] < 1 || $selection['limit'] > 10) {
                    throw new \InvalidArgumentException('Page is outside the read window.');
                }
            }
            $path = PrintifyApiRequest::prepare($operation, $scope['shop'], $selection)['endpoint'];
        } else {
            throw new \InvalidArgumentException('Unsupported read operation.');
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
        $operation = $request['operation'];
        $selection = $request['selection'];
        $shop = $request['scope']['shop'];
        if (isset(self::CATALOG[$operation])) {
            $catalog = PrintifyCatalogReadContract::select($data, ['kind'=>self::CATALOG[$operation]] + $selection);
            $summary = static fn(array $row): array => ['id'=>$row['id'],'title'=>$row['title'] ?? null];
            return match ($operation) {
                'BLUEPRINTS_READ','BLUEPRINT_PROVIDERS_READ' => array_map($summary, $catalog),
                'BLUEPRINT_READ','BLUEPRINT_PROVIDER_READ' => $summary($catalog),
                'VARIANTS_READ' => ['blueprint_id'=>$selection['blueprint_id'],
                    'variants'=>array_map($summary, $catalog['variants'])],
                'VARIANT_READ' => $summary($catalog) + ['print_areas'=>PrintifyCatalogReadContract::variantAreas($catalog)],
                'CATALOG_SHIPPING_READ' => ['methods'=>array_map(
                    static fn(array $row): string => $row['attributes']['name'], $catalog['data']), 'address_quote'=>false],
                'CATALOG_SHIPPING_METHOD_READ' => ['rates'=>array_map(
                    static fn(array $row): array => [
                        'variant_id'=>$row['attributes']['variantId'],
                        'method'=>$row['attributes']['shippingType'],
                        'country'=>$row['attributes']['country']['code'],
                        'first_item'=>$row['attributes']['shippingCost']['firstItem']['amount'],
                        'additional_item'=>$row['attributes']['shippingCost']['additionalItems']['amount'],
                        'currency'=>$row['attributes']['shippingCost']['firstItem']['currency'],
                    ], $catalog['data']), 'address_quote'=>false],
            };
        }
        if (in_array($operation, ['PRODUCTS_READ','ORDERS_READ','PRODUCT_READ','ORDER_READ'], true)) {
            $rows = in_array($operation, ['PRODUCTS_READ','ORDERS_READ'], true) ? ($data['data'] ?? null) : [$data];
            if (!is_array($rows)) {
                throw new \InvalidArgumentException('Missing records.');
            }
            foreach ($rows as $row) {
                if (!is_array($row) || (array_key_exists('shop_id', $row)
                    && ((!is_int($row['shop_id']) && !is_string($row['shop_id'])) || (string)$row['shop_id'] !== $shop))) {
                    throw new \InvalidArgumentException('Response scope mismatch.');
                }
            }
        }
        $projection = PrintifyApiReadEvidence::project($operation, $data, $shop, $selection);
        if (($projection['next_page']['page'] ?? 0) > self::MAX_PAGE) {
            $projection['next_page'] = null;
            $projection['page_window_exhausted'] = true;
        }
        return $projection;
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
