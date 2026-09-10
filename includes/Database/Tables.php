<?php
declare(strict_types=1);
namespace DigiForge\Database;
final class Tables {
    private static function name(string $suffix): string { global $wpdb; return $wpdb->prefix . 'digiforge_' . $suffix; }
    public static function settings(): string { return self::name('settings'); }
    public static function audit_log(): string { return self::name('audit_log'); }
    public static function jobs(): string { return self::name('jobs'); }
    public static function idempotency(): string { return self::name('idempotency'); }
    public static function opportunities(): string { return self::name('opportunities'); }
    public static function product_families(): string { return self::name('product_families'); }
    public static function products(): string { return self::name('products'); }
    public static function product_versions(): string { return self::name('product_versions'); }
}
