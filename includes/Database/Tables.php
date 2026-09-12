<?php
declare(strict_types=1);
namespace DigiForge\Database;
final class Tables {
    private static function name(string $suffix): string { global $wpdb; return $wpdb->prefix . 'digiforge_' . $suffix; }
    public static function settings(): string { return self::name('settings'); }
    public static function audit_log(): string { return self::name('audit_log'); }
    public static function jobs(): string { return self::name('jobs'); }
    public static function idempotency(): string { return self::name('idempotency'); }
    public static function health_events(): string { return self::name('health_events'); }
    public static function opportunities(): string { return self::name('opportunities'); }
    public static function product_families(): string { return self::name('product_families'); }
    public static function products(): string { return self::name('products'); }
    public static function product_versions(): string { return self::name('product_versions'); }
    public static function digital_products(): string { return self::name('digital_products'); }
    public static function digital_files(): string { return self::name('digital_files'); }
    public static function digital_file_versions(): string { return self::name('digital_file_versions'); }
    public static function digital_packages(): string { return self::name('digital_packages'); }
    public static function digital_previews(): string { return self::name('digital_previews'); }
    public static function digital_templates(): string { return self::name('digital_templates'); }
    public static function digital_licenses(): string { return self::name('digital_licenses'); }
    public static function digital_download_checks(): string { return self::name('digital_download_checks'); }
    public static function integrations(): string { return self::name('integrations'); }
    public static function integration_secrets(): string { return self::name('integration_secrets'); }
    public static function research_sources(): string { return self::name('research_sources'); }
    public static function research_observations(): string { return self::name('research_observations'); }
    public static function research_evidence(): string { return self::name('research_evidence'); }
    public static function research_candidates(): string { return self::name('research_candidates'); }
    public static function research_candidate_evidence(): string { return self::name('research_candidate_evidence'); }
    public static function research_reviews(): string { return self::name('research_reviews'); }
}
