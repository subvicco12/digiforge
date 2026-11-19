<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

use DigiForgePrintifyApi\Engine\POD\{PrintifyApiOutcome, PrintifyOrderEvidence, PrintifyProductRequest};

if (!\defined('ABSPATH')) {
    exit;
}

/** In-memory fixture review. It cannot reserve or reconcile a native operation. */
final class OfflineLifecycle
{
    private const ENGINE = [
        'POD/PrintifyApiOutcome.php', 'POD/PrintifyOrderEvidence.php',
        'POD/PrintifyProductRequest.php', 'Research/DesignFactoryContract.php',
    ];

    public static function available(): bool
    {
        foreach (self::ENGINE as $path) {
            if (!is_readable(__DIR__ . '/Engine/' . $path)) {
                return false;
            }
        }
        return true;
    }

    public static function review(string $shop, array $intent, array $fixture): array
    {
        try {
            return self::inspect($shop, $intent, $fixture);
        } catch (\Throwable) {
            return ['state' => 'DENIED', 'provider_execution_authorized' => false, 'retry_permitted' => false];
        }
    }

    private static function inspect(string $shop, array $i, array $f): array
    {
        self::require(self::available());
        foreach (self::ENGINE as $path) {
            require_once __DIR__ . '/Engine/' . $path;
        }
        self::keys($i, ['operation_id','approval_sha256','version','account_reference','product_id','order_id']);
        self::keys($f, ['scope','catalog_body','product','artwork','artwork_reference','order','status','readback']);
        foreach (['operation_id','approval_sha256'] as $key) {
            self::require(is_string($i[$key]) && preg_match('/^[a-f0-9]{64}$/D', $i[$key]) === 1);
        }
        foreach (['account_reference','product_id','order_id'] as $key) {
            self::require(is_string($i[$key]) && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $i[$key]) === 1);
        }
        self::require(is_int($i['version']) && $i['version'] > 0);
        self::require($f['scope'] === ['shop'=>$shop,'account_reference'=>$i['account_reference'],
            'version'=>$i['version'],'operation_id'=>$i['operation_id'],'approval_sha256'=>$i['approval_sha256']]);
        $product = PrintifyProductRequest::prepare('PRODUCT_CREATE', $shop, $f['product']);
        $catalog = Runtime::validateCatalog($shop, [
            'kind'=>'variants','blueprint_id'=>$f['product']['blueprint_id'],'provider_id'=>$f['product']['print_provider_id'],
        ], $f['catalog_body']);
        self::require($catalog['state'] === 'LOCAL_VALIDATION_PASSED');
        $catalogIds = array_column(json_decode($f['catalog_body'], true, 24, JSON_THROW_ON_ERROR)['variants'], 'id');
        $selected = array_column(array_filter($f['product']['variants'], static fn(array $v): bool => $v['is_enabled']), 'id');
        self::require($selected !== [] && array_diff($selected, $catalogIds) === []);
        $artwork = PrintifyProductRequest::prepare('IMAGE_UPLOAD', $shop, $f['artwork']);
        foreach ($f['product']['print_areas'] as $area) {
            foreach ($area['placeholders'] as $placeholder) {
                foreach ($placeholder['images'] as $image) {
                    self::require($image['id'] === $f['artwork_reference']);
                }
            }
        }
        self::bounded($f['order']);
        foreach ($f['order']['line_items'] as $line) {
            self::require(is_array($line) && !array_key_exists('personalisation', $line)
                && ($line['product_id'] ?? null) === $i['product_id']
                && in_array($line['variant_id'] ?? null, $selected, true));
        }
        PrintifyOrderEvidence::validate($f['order']);
        self::require($f['order']['external_id'] === $i['operation_id']);
        self::require(is_int($f['status']) && ($f['status'] === 0 || ($f['status'] >= 100 && $f['status'] <= 599)));
        $outcome = PrintifyApiOutcome::classify($f['status'], [], 1, 'POST', 1);
        $readback = null;
        if ($f['readback'] !== null) {
            self::bounded($f['readback']);
            self::require(is_array($f['readback']) && ($f['readback']['shop_id'] ?? null) === $shop);
            $observed = PrintifyOrderEvidence::project($f['readback'], $i['order_id']);
            self::require($observed['external_id'] === $i['operation_id']
                && self::lineTotals($observed['line_items']) === self::lineTotals($f['order']['line_items']));
            $readback = ['observation' => 'SYNTHETIC_MATCH', 'status' => $observed['status'],
                'tracking_hashes' => array_column($observed['shipments'], 'tracking_hash')];
        }
        return [
            'state' => 'OFFLINE_REVIEWED', 'scope' => $f['scope'], 'catalog_sha256' => $catalog['snapshot_sha256'],
            'product_fingerprint' => $product['fingerprint'], 'artwork_fingerprint' => $artwork['fingerprint'],
            'order_sha256' => hash('sha256', json_encode($f['order'], JSON_THROW_ON_ERROR)),
            'synthetic_outcome' => $outcome['category'], 'tracking_readback' => $readback,
            'provider_outcome_confirmed' => false, 'provider_execution_authorized' => false, 'retry_permitted' => false,
        ];
    }

    private static function lineTotals(array $lines): array
    {
        $totals = [];
        foreach ($lines as $line) {
            $key = $line['product_id'] . ':' . $line['variant_id'];
            $totals[$key] = ($totals[$key] ?? 0) + $line['quantity'];
        }
        ksort($totals, SORT_STRING);
        return $totals;
    }

    private static function bounded(mixed $value): void
    {
        self::require(is_array($value) && strlen(json_encode($value, JSON_THROW_ON_ERROR, 24)) <= 262144);
    }

    private static function keys(array $data, array $keys): void
    {
        self::require(count($data) === count($keys) && array_diff($keys, array_keys($data)) === []);
    }

    private static function require(bool $valid): void
    {
        if (!$valid) {
            throw new \InvalidArgumentException('Offline lifecycle evidence denied.');
        }
    }
}
