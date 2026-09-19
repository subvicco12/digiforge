<?php
/** Uninstall only removes business data when an administrator explicitly opted in. */
declare(strict_types=1);
if (! defined('WP_UNINSTALL_PLUGIN')) { exit; }
// DigiForge V1 is single-site. Never perform destructive uninstall operations during multisite/network uninstall.
if (is_multisite()) { return; }
if (! get_option('digiforge_cleanup_on_uninstall', false)) { return; }
require_once __DIR__ . '/includes/Core/Capabilities.php';
\DigiForge\Core\Capabilities::remove();
global $wpdb;
foreach (['settings','audit_log','jobs','idempotency','health_events','opportunities','product_families','products','product_versions','digital_products','digital_files','digital_file_versions','digital_packages','digital_previews','digital_templates','digital_licenses','digital_download_checks','integration_secrets','integrations','research_candidate_evidence','research_reviews','research_candidates','research_evidence','research_observations','research_sources','ai_reviews','ai_usage','ai_outputs','ai_runs','ai_prompt_versions','ai_prompts','ai_models','ai_tasks','release_bundle_revisions','release_bundles','production_qa','asset_revisions','production_intents','production_plan_assets','production_plans','asset_specs','pod_readiness_reviews','pod_cost_snapshots','pod_provider_intents','personalization_bindings','personalization_schemas','pod_print_areas','pod_mappings','pod_catalog','listing_readiness_reviews','etsy_intents','etsy_draft_packages','listing_pod_bindings','listing_media','listing_seo','listings','fulfillment_readiness_reviews','fulfillment_intents','fulfillment_plans','personalization_submissions','order_line_items','orders','finance_intents','operational_alerts','analytics_snapshots','finance_periods','tax_classifications','fx_snapshots','finance_ledger'] as $table) { $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($wpdb->prefix . 'digiforge_' . $table) . '`'); }
delete_option('digiforge_db_version');
delete_option('digiforge_db_schema_version');
delete_option('digiforge_last_migration_failure');
delete_option('digiforge_last_audit_failure');
delete_option('digiforge_credential_key_envelope');
delete_option('digiforge_cleanup_on_uninstall');
