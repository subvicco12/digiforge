<?php
declare(strict_types=1);

require __DIR__ . '/../includes/Core/Config.php';
require __DIR__ . '/../includes/Queue/JobState.php';

use DigiForge\Core\Config;
use DigiForge\Queue\JobState;

function expect(bool $condition, string $message): void { if (! $condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
function source(string $path): string { return (string) file_get_contents(__DIR__ . '/../' . $path); }

expect(Config::default_settings()['stop_all'] === true, 'STOP ALL must default to ON');
expect(Config::default_settings()['automation_armed'] === false, 'automation must default to unarmed');
expect(count(Config::SWITCHES) === 10, 'all automation switches must be declared');
expect(Config::allowed_switch('etsy_publish'), 'known switch accepted');
expect(! Config::allowed_switch('external_publish'), 'unknown switch rejected');
expect(Config::setting_type('stop_all') === 'boolean', 'controls are typed');
expect(! Config::writable_setting('automation_armed'), 'arming cannot be changed through ordinary settings');
expect(! Config::valid_value('stop_all', '1'), 'boolean settings reject string coercion');

foreach (['QUEUED','RUNNING','WAITING','RETRY','SUCCESS','FAILED','BLOCKED','CANCELLED','HUMAN_REVIEW','DEAD_LETTER'] as $state) { expect(JobState::valid($state), "$state is valid"); }
expect(JobState::terminal('SUCCESS'), 'success is terminal');
expect(! JobState::terminal('RUNNING'), 'running is non-terminal');

$capabilities = source('includes/Core/Capabilities.php');
foreach (['manage_digiforge','manage_digiforge_products','manage_digiforge_digital','manage_digiforge_research','manage_digiforge_ai','manage_digiforge_production','manage_digiforge_pod','manage_digiforge_listings','manage_digiforge_automation','manage_digiforge_connections','manage_digiforge_settings','publish_digiforge','view_digiforge_analytics'] as $capability) { expect(str_contains($capabilities, "'$capability'"), "$capability capability declared"); }

$bootstrap = source('digiforge.php');
expect(str_contains($bootstrap, "spl_autoload_register('digiforge_autoload')"), 'internal autoloader is registered');
expect(str_contains($bootstrap, 'register_activation_hook'), 'activation hook is registered');
expect(str_contains($bootstrap, "DIGIFORGE_DB_VERSION = '11'"), 'database schema version is current');
expect((bool) preg_match('/^\s*\*\s*Version:\s*0\.8\.0\s*$/m', $bootstrap), 'plugin header release version is current');
expect((bool) preg_match("/const\\s+DIGIFORGE_VERSION\\s*=\\s*'0\\.8\\.0'\\s*;/", $bootstrap), 'runtime release version is current');

$settings = source('includes/Core/Settings.php');
foreach (['automation_armed', "self::get('stop_all', true)", 'Config::valid_value', 'safety_locked'] as $guard) { expect(str_contains($settings, $guard), "$guard fail-closed guard exists"); }
expect(str_contains($settings, "'cleanup_on_uninstall'"), 'uninstall cleanup is explicit');

$rest = source('includes/REST/Controller.php');
expect(str_contains($rest, "'digiforge/v1'"), 'REST namespace is registered');
expect(str_contains($rest, "'permission_callback'"), 'REST permission callbacks are present');
expect(str_contains($rest, "'manage_digiforge_automation'"), 'control writes require automation capability');

$jobs = source('includes/Database/Migrator.php');
expect(str_contains($jobs, 'idempotency_key varchar(191) NULL DEFAULT NULL'), 'job idempotency key permits NULL');
expect(str_contains($jobs, "SET idempotency_key = NULL WHERE idempotency_key = ''"), 'legacy empty idempotency keys are migrated to NULL');

$productionSchema = source('includes/Database/ProductionSchema.php');
foreach (['asset_specs','production_plans','production_intents','asset_revisions','production_qa','release_bundles'] as $table) { expect(str_contains($productionSchema, "Tables::$table()"), "$table Batch 6 table declared"); }
expect(str_contains($productionSchema, '$currentVersion === 8'), 'v8 to v9 production-only migration is explicit');

$podSchema = source('includes/Database/PodSchema.php');
foreach (['pod_catalog','pod_mappings','pod_print_areas','personalization_schemas','personalization_bindings','pod_provider_intents','pod_cost_snapshots','pod_readiness_reviews'] as $table) { expect(str_contains($podSchema, "Tables::$table()"), "$table Batch 7 table declared"); }
expect(str_contains($podSchema, '$currentVersion === 9'), 'v9 to v10 POD-only migration is explicit');

$listingSchema = source('includes/Database/ListingSchema.php');
foreach (['listings','listing_seo','listing_media','listing_pod_bindings','etsy_draft_packages','etsy_intents','listing_readiness_reviews'] as $table) { expect(str_contains($listingSchema, "Tables::$table()"), "$table Batch 8 table declared"); }
expect(str_contains($listingSchema, '$currentVersion === 10'), 'v10 to v11 listing-only migration is explicit');

$podController = source('includes/REST/PodController.php');
expect(str_contains($podController, "manage_digiforge_pod"), 'POD REST requires POD capability');
expect(str_contains($podController, "Idempotency-Key"), 'POD mutations require idempotency');
$podRepo = source('includes/POD/Repository.php');
expect(str_contains($podRepo, "'state' => 'BLOCKED'"), 'provider intents are inert and blocked');
expect(! str_contains($podRepo, 'wp_remote_'), 'POD repository has no external HTTP client');

$listingController = source('includes/REST/ListingController.php');
expect(str_contains($listingController, "manage_digiforge_listings"), 'listing REST requires listing capability');
expect(str_contains($listingController, "Idempotency-Key"), 'listing mutations require idempotency');
expect(str_contains($listingController, 'MAX_BODY_BYTES'), 'listing mutations enforce request body bounds');
$listingRepo = source('includes/Listings/Repository.php');
expect(str_contains($listingRepo, "'state' => 'BLOCKED'"), 'Etsy intents are inert and blocked');
expect(! str_contains($listingRepo, 'wp_remote_'), 'listing repository has no external HTTP client');

$jobRepository = source('includes/Queue/JobRepository.php');
expect(str_contains($jobRepository, "'idempotency_key' => \$idempotencyKey !== '' ? \$idempotencyKey : null"), 'jobs without idempotency keys insert NULL');
expect(str_contains($jobRepository, 'JobState::canTransition'), 'jobs enforce legal transitions');
expect(str_contains($jobRepository, 'lease_expires_at'), 'jobs use expiring leases');

$logger = source('includes/Security/Logger.php');
foreach (['password','secret','token','accesstoken','refreshtoken','clientsecret','authorization','apikey','credential','privatekey','signingkey'] as $credentialKey) { expect(str_contains($logger, "'$credentialKey'"), "$credentialKey redaction rule declared"); }
expect(str_contains($logger, "preg_replace('/[^a-z0-9]/i"), 'credential key normalization is present');

$uninstall = source('uninstall.php');
expect(str_contains($uninstall, 'if (is_multisite()) { return; }'), 'multisite uninstall is non-destructive');
expect(str_contains($uninstall, "'digiforge_db_schema_version'"), 'schema version cleanup is present');
expect(str_contains($uninstall, "'ai_runs'"), 'Batch 5 AI tables participate in opt-in cleanup');
expect(str_contains($uninstall, "'release_bundles'"), 'Batch 6 production tables participate in opt-in cleanup');
expect(str_contains($uninstall, "'pod_mappings'"), 'Batch 7 POD tables participate in opt-in cleanup');

echo "DigiForge foundation tests passed.\n";