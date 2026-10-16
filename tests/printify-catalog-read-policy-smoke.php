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

    use DigiForge\Core\Settings;
    use DigiForge\POD\PrintifyCatalogReadPolicy;

    function check(bool $condition, string $message): void {
        if (!$condition) { throw new RuntimeException($message); }
    }
    check(PrintifyCatalogReadPolicy::authorize(0, 28) instanceof WP_Error, 'Invalid identity must fail');
    check(PrintifyCatalogReadPolicy::authorize(478, 28) instanceof WP_Error, 'Default state must deny');
    Settings::$values = [
        'stop_all' => false,
        'printify_activation_authorized' => true,
        'enabled_printify' => true,
        'enabled_research' => true,
    ];
    check(PrintifyCatalogReadPolicy::authorize(478, 28) instanceof WP_Error, 'External safety lock must deny');
    Settings::$locked = false;
    Settings::$values['stop_all'] = true;
    check(PrintifyCatalogReadPolicy::authorize(478, 28) instanceof WP_Error, 'STOP ALL must deny');
    Settings::$values['stop_all'] = false;
    Settings::$values['enabled_research'] = false;
    check(PrintifyCatalogReadPolicy::authorize(478, 28) instanceof WP_Error, 'Research disabled must deny');
    Settings::$values['enabled_research'] = true;
    Settings::$values['printify_activation_authorized'] = false;
    check(PrintifyCatalogReadPolicy::authorize(478, 28) instanceof WP_Error, 'Printify authorization missing must deny');
    Settings::$values['printify_activation_authorized'] = true;
    check(PrintifyCatalogReadPolicy::authorize(478, 28) === true, 'All independently configured gates should permit offline preflight');
    echo "Printify catalog read policy offline tests passed\n";
}
