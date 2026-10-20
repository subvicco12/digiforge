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

    // Former callback-only success is intentionally denied even with every global flag set.
    $denied = PrintifyCatalogPreview::inspect(478, 28, $variants, $shipping);
    verify($denied instanceof WP_Error && $reads === 0, 'Global flags cannot authorize callback-only preview');
    $bypass = static function (): array { throw new RuntimeException('Callback must not execute'); };
    verify(PrintifyCatalogPreview::inspect(478, 28, $bypass, $bypass) instanceof WP_Error, 'Permissive supplier callbacks cannot bypass identity admission');
    // Positive projection, malformed variants, rights UNKNOWN, replay and in-flight
    // changes are exercised against real WordPress grants in PrintifyCatalogScopedSmokeTest.
    require __DIR__ . '/printify-catalog-shipping-contract-smoke.php';
    echo "Printify catalog preview offline smoke passed\n";
}
