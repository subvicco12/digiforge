<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!\defined('ABSPATH')) {
    exit;
}

/** Internal v1 catalog read model. Its only current source is the local synthetic transport. */
final class CatalogBrowser
{
    public const VERSION = 1;
    private const PAGE_SIZE = 25;

    public static function read(
        int $version, string $shop, string $account, array $selection = [],
        ?SyntheticPrintifyTransport $fixture = null
    ): array {
        $base = [
            'version'=>self::VERSION, 'state'=>'DENIED', 'error'=>null, 'scope'=>null, 'data'=>null,
            'source'=>null, 'observed_at'=>null, 'provider_observed_at'=>null, 'freshness'=>'UNAVAILABLE', 'provider_fact_verified'=>false,
            'provider_execution_authorized'=>false, 'address_quote'=>false, 'retry_permitted'=>false,
        ];
        if ($version !== self::VERSION) {
            return ['error'=>'INCOMPATIBLE_VERSION'] + $base;
        }
        if (!Runtime::permitsCatalogFixture($shop, $account)) {
            return ['error'=>'SCOPE_OR_TRANSPORT_UNAVAILABLE'] + $base;
        }
        $selection += ['blueprint_id'=>null, 'provider_id'=>null, 'variant_id'=>null, 'method'=>null, 'page'=>1];
        if (count($selection) !== 5 || !is_int($selection['page']) || $selection['page'] < 1 || $selection['page'] > 40
            || ($selection['provider_id'] !== null && $selection['blueprint_id'] === null)
            || ($selection['variant_id'] !== null && $selection['provider_id'] === null)
            || ($selection['method'] !== null && ($selection['variant_id'] === null || !is_string($selection['method'])))) {
            return ['error'=>'INVALID_SELECTION'] + $base;
        }
        $plan = [];
        foreach (['blueprint_id', 'provider_id', 'variant_id'] as $key) {
            if ($selection[$key] !== null && (!is_int($selection[$key]) || $selection[$key] < 1)) {
                return ['error'=>'INVALID_SELECTION'] + $base;
            }
            if ($selection[$key] !== null) {
                $plan[$key] = $selection[$key];
            }
        }

        $client = new SyntheticPrintifyClient($shop, $fixture ?? new SyntheticPrintifyTransport($shop));
        $read = static function (string $operation, array $input = []) use ($client): array {
            $result = $client->run($operation, $input);
            if ($result['state'] !== 'SYNTHETIC_OBSERVED') {
                throw new \RuntimeException(match ($result['status']) {429=>'RATE_LIMITED',403=>'SCOPE_OR_TRANSPORT_UNAVAILABLE',default=>$result['reason']},
                    (int)($result['bounded_retry_after'] ?? 0));
            }
            return $result['data'];
        };

        try {
            $shopEvidence = $read('SHOPS_READ')['shops'][0];
            $blueprints = self::summaries($read('BLUEPRINTS_READ'));
            $data = ['shop'=>$shopEvidence, 'blueprints'=>self::page($blueprints, $selection['page']),
                'providers'=>null, 'variants'=>null, 'print_areas'=>null, 'shipping_methods'=>null, 'catalog_rates'=>null];
            if (isset($plan['blueprint_id'])) {
                self::selected($blueprints, $plan['blueprint_id']);
                $bp = array_intersect_key($plan, ['blueprint_id'=>true]);
                $providers = self::summaries($read('BLUEPRINT_PROVIDERS_READ', $bp));
                $data['providers'] = self::page($providers, $selection['page']);
            }
            if (isset($plan['provider_id'])) {
                self::selected($providers, $plan['provider_id']);
                $provider = array_intersect_key($plan, ['blueprint_id'=>true, 'provider_id'=>true]);
                $response = $read('VARIANTS_READ', $provider);
                if (($response['id'] ?? null) !== $plan['blueprint_id']) {
                    throw new \RuntimeException('RESPONSE_VALIDATION');
                }
                $variants = self::summaries($response['variants']);
                $data['variants'] = self::page($variants, $selection['page']);
            }
            if (isset($plan['variant_id'])) {
                self::selected($variants, $plan['variant_id']);
                $variant = $read('VARIANT_READ', $plan);
                $data['print_areas'] = self::areas($variant);
                $methods = $read('CATALOG_SHIPPING_READ', $provider);
                $data['shipping_methods'] = array_map(static fn(array $row): string => $row['attributes']['name'], $methods['data']);
            }
            if ($selection['method'] !== null) {
                if (!in_array($selection['method'], $data['shipping_methods'], true)) {
                    throw new \RuntimeException('UNSUPPORTED_SELECTION');
                }
                $shipping = $read('CATALOG_SHIPPING_METHOD_READ', $provider + ['method'=>$selection['method']]);
                $data['catalog_rates'] = [];
                foreach ($shipping['data'] as $row) {
                    $rate = $row['attributes'];
                    if ($rate['variantId'] === $plan['variant_id']) {
                        $data['catalog_rates'][] = [
                            'method'=>$rate['shippingType'], 'country'=>$rate['country']['code'],
                            'first_item'=>$rate['shippingCost']['firstItem']['amount'],
                            'additional_item'=>$rate['shippingCost']['additionalItems']['amount'],
                            'currency'=>$rate['shippingCost']['firstItem']['currency'],
                        ];
                    }
                }
            }
            return ['state'=>'OBSERVED', 'scope'=>['shop'=>$shop, 'account'=>$account, 'integration'=>null],
                'source'=>'SYNTHETIC_PRINTIFY', 'observed_at'=>time(), 'freshness'=>'FIXTURE_ONLY', 'data'=>$data] + $base;
        } catch (\RuntimeException $error) {
            return ['retry_after'=>$error->getCode() > 0 ? $error->getCode() : null, 'error'=>in_array($error->getMessage(), ['RATE_LIMITED','SCOPE_OR_TRANSPORT_UNAVAILABLE','REQUEST_VALIDATION','RESPONSE_VALIDATION',
                'UNSUPPORTED_SELECTION'], true) ? $error->getMessage() : 'READ_UNAVAILABLE'] + $base;
        } catch (\Throwable) {
            return ['error'=>'RESPONSE_VALIDATION'] + $base;
        }
    }

    private static function summaries(array $rows): array
    {
        return array_map(static fn(array $row): array => [
            'id'=>$row['id'], 'title'=>is_string($row['title'] ?? null) ? substr($row['title'], 0, 300) : null,
        ], $rows);
    }

    private static function selected(array $rows, int $id): void
    {
        if (!in_array($id, array_column($rows, 'id'), true)) {
            throw new \RuntimeException('UNSUPPORTED_SELECTION');
        }
    }

    private static function page(array $rows, int $page): array
    {
        return ['rows'=>array_slice($rows, ($page-1)*self::PAGE_SIZE, self::PAGE_SIZE),
            'page'=>$page, 'next_page'=>count($rows)>$page*self::PAGE_SIZE ? $page+1 : null,
            'provider_pagination_followed'=>false];
    }

    private static function areas(array $variant): ?array
    {
        if (!array_key_exists('placeholders', $variant)) {
            return null;
        }
        $areas = $variant['placeholders'];
        if (!is_array($areas) || !array_is_list($areas) || count($areas)>50) {
            throw new \RuntimeException('RESPONSE_VALIDATION');
        }
        $out = [];
        foreach ($areas as $area) {
            if (!is_array($area) || !is_string($area['position'] ?? null)
                || preg_match('/^[A-Za-z0-9_-]{1,60}$/D', $area['position']) !== 1) {
                throw new \RuntimeException('RESPONSE_VALIDATION');
            }
            foreach (['width', 'height'] as $dimension) {
                if (isset($area[$dimension]) && (!is_int($area[$dimension]) || $area[$dimension]<1)) {
                    throw new \RuntimeException('RESPONSE_VALIDATION');
                }
            }
            $out[] = ['position'=>$area['position'], 'width'=>$area['width'] ?? null,
                'height'=>$area['height'] ?? null, 'bleed'=>null, 'safe_zone'=>null];
        }
        return $out;
    }
}
