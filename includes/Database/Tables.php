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
    public static function ai_tasks(): string { return self::name('ai_tasks'); }
    public static function ai_models(): string { return self::name('ai_models'); }
    public static function ai_prompts(): string { return self::name('ai_prompts'); }
    public static function ai_prompt_versions(): string { return self::name('ai_prompt_versions'); }
    public static function ai_runs(): string { return self::name('ai_runs'); }
    public static function ai_outputs(): string { return self::name('ai_outputs'); }
    public static function ai_usage(): string { return self::name('ai_usage'); }
    public static function ai_reviews(): string { return self::name('ai_reviews'); }
    public static function asset_specs(): string { return self::name('asset_specs'); }
    public static function production_plans(): string { return self::name('production_plans'); }
    public static function production_plan_assets(): string { return self::name('production_plan_assets'); }
    public static function production_intents(): string { return self::name('production_intents'); }
    public static function asset_revisions(): string { return self::name('asset_revisions'); }
    public static function production_qa(): string { return self::name('production_qa'); }
    public static function release_bundles(): string { return self::name('release_bundles'); }
    public static function release_bundle_revisions(): string { return self::name('release_bundle_revisions'); }
}
