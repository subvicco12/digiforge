<?php
declare(strict_types=1);

namespace DigiForge\Core {
    final class Settings {
        public static bool $locked = true;
        public static array $values = [];
        public static function safety_locked(): bool { return self::$locked; }
        public static function get(string $key, mixed $default = null): mixed { return self::$values[$key] ?? $default; }
        public static function is_enabled(string $key): bool { return self::$values['enabled_' . $key] ?? false; }
    }
}
namespace {
    class WP_Error {
        public function __construct(public string $code, public string $message, public array $data = []) {}
    }
    function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
    require dirname(__DIR__) . '/includes/POD/PrintifyCatalogReadPolicy.php';
    require dirname(__DIR__) . '/includes/POD/PrintifyCatalogPreview.php';

    use DigiForge\Core\Settings;
    use DigiForge\POD\PrintifyCatalogPreview;

    function verify(bool $condition, string $reason): void {
        if (!$condition) { throw new RuntimeException($reason); }
    }

    $reads = 0;
    $variants = static function (int $blueprint, int $provider) use (&$reads): array {
        ++$reads;
        return ['variants' => [
            ['id' => 123, 'title' => '11oz', 'is_available' => true, 'cost' => 503],
            ['id' => 456, 'title' => '15oz', 'is_available' => false, 'cost' => 613],
        ]];
    };
    $shipping = static function (int $blueprint, int $provider) use (&$reads): array {
        ++$reads;
        return ['private_or_unvalidated' => 'do not expose'];
    };
    $invalid = PrintifyCatalogPreview::inspect(0, 28, $variants, $shipping);
    verify($invalid instanceof WP_Error && $reads === 0, 'Invalid identity must deny before reads');
    $denied = PrintifyCatalogPreview::inspect(478, 28, $variants, $shipping);
    verify($denied instanceof WP_Error && $reads === 0, 'Default safety lock must deny before reads');

    Settings::$values = [
        'stop_all' => false,
        'printify_activation_authorized' => true,
        'enabled_printify' => true,
        'enabled_research' => true,
    ];
    verify(PrintifyCatalogPreview::inspect(478, 28, $variants, $shipping) instanceof WP_Error && $reads === 0, 'External lock must deny');
    Settings::$locked = false;
    Settings::$values['stop_all'] = true;
    verify(PrintifyCatalogPreview::inspect(478, 28, $variants, $shipping) instanceof WP_Error && $reads === 0, 'STOP ALL must deny');
    Settings::$values['stop_all'] = false;

    $ok = PrintifyCatalogPreview::inspect(478, 28, $variants, $shipping);
    verify(is_array($ok) && $reads === 2, 'Authorized offline reads must succeed');
    verify($ok['persistence'] === 'NONE' && $ok['certification'] === 'NOT_CERTIFIED', 'Preview cannot certify or persist');
    verify($ok['variants'][0]['variant_id'] === 123 && $ok['variants'][0]['print_area'] === null, 'Variant identity and unknown geometry');
    verify($ok['variants'][0]['geometry_status'] === 'UNKNOWN' && $ok['shipping_country_rates'] === null, 'Unknown geometry and shipping rates');
    verify(strpos(json_encode($ok, JSON_THROW_ON_ERROR), 'do not expose') === false, 'Raw shipping must not leak');

    $readsBeforeLock = $reads;
    $lockDuringVariants = static function (int $blueprint, int $provider) use (&$reads): array {
        ++$reads;
        Settings::$locked = true;
        return ['variants' => [['id' => 789]]];
    };
    $blockedShipping = PrintifyCatalogPreview::inspect(478, 28, $lockDuringVariants, $shipping);
    verify($blockedShipping instanceof WP_Error, 'Mid-read safety lock must deny shipping');
    verify($reads === $readsBeforeLock + 1, 'Shipping callback must not execute after lock');
    Settings::$locked = false;

    $bad = PrintifyCatalogPreview::inspect(478, 28, static fn() => ['variants' => [['id' => '123']]], $shipping);
    verify($bad instanceof WP_Error && $bad->code === 'digiforge_printify_preview_variant', 'Malformed variants must deny');
    $transportError = new WP_Error('offline', 'blocked');
    $failed = PrintifyCatalogPreview::inspect(478, 28, static fn() => $transportError, $shipping);
    verify($failed === $transportError, 'Transport errors must propagate');
    echo "Printify catalog preview offline smoke passed\n";
}
