<?php
declare(strict_types=1);
// Lightweight structural checks that run without WordPress or PHPUnit.
require __DIR__ . '/../includes/Core/Config.php';
require __DIR__ . '/../includes/Queue/JobState.php';
use DigiForge\Core\Config;
use DigiForge\Queue\JobState;
function expect(bool $condition, string $message): void { if (! $condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
function source(string $path): string { return (string) file_get_contents(__DIR__ . '/../' . $path); }
expect(Config::default_settings()['stop_all'] === false, 'STOP ALL must default to OFF');
expect(count(Config::SWITCHES) === 10, 'all automation switches must be declared');
expect(Config::allowed_switch('etsy_publish'), 'known switch accepted');
expect(! Config::allowed_switch('external_publish'), 'unknown switch rejected');
foreach (['QUEUED','RUNNING','WAITING','RETRY','SUCCESS','FAILED','BLOCKED','CANCELLED','HUMAN_REVIEW'] as $state) { expect(JobState::valid($state), "$state is valid"); }
expect(JobState::terminal('SUCCESS'), 'success is terminal');
expect(! JobState::terminal('RUNNING'), 'running is non-terminal');
$capabilities = source('includes/Core/Capabilities.php');
foreach (['manage_digiforge','manage_digiforge_products','manage_digiforge_research','manage_digiforge_automation','manage_digiforge_connections','manage_digiforge_settings','publish_digiforge','view_digiforge_analytics'] as $capability) { expect(str_contains($capabilities, "'$capability'"), "$capability capability declared"); }
$bootstrap = source('digiforge.php');
expect(str_contains($bootstrap, "spl_autoload_register('digiforge_autoload')"), 'internal autoloader is registered');
expect(str_contains($bootstrap, 'register_activation_hook'), 'activation hook is registered');
expect(str_contains($bootstrap, "DIGIFORGE_DB_VERSION = '2'"), 'database schema version is current');
$settings = source('includes/Core/Settings.php');
expect(str_contains($settings, "'cleanup_on_uninstall'"), 'uninstall cleanup is explicit');
$rest = source('includes/REST/Controller.php');
expect(str_contains($rest, "'digiforge/v1'"), 'REST namespace is registered');
expect(str_contains($rest, "'permission_callback'"), 'REST permission callbacks are present');
expect(str_contains($rest, "'manage_digiforge_automation'"), 'control writes require automation capability');
$jobs = source('includes/Database/Migrator.php');
expect(str_contains($jobs, 'idempotency_key varchar(191) NULL DEFAULT NULL'), 'job idempotency key permits NULL');
expect(str_contains($jobs, "SET idempotency_key = NULL WHERE idempotency_key = ''"), 'legacy empty idempotency keys are migrated to NULL');
$job_repository = source('includes/Queue/JobRepository.php');
expect(str_contains($job_repository, "'idempotency_key'] = null"), 'jobs without idempotency keys insert NULL');
$logger = source('includes/Security/Logger.php');
foreach (['password','secret','token','accesstoken','refreshtoken','clientsecret','authorization','apikey','credential','privatekey','signingkey'] as $credential_key) { expect(str_contains($logger, "'$credential_key'"), "$credential_key redaction rule declared"); }
expect(str_contains($logger, "preg_replace('/[^a-z0-9]/i", 'credential key normalization is present');
$uninstall = source('uninstall.php');
expect(str_contains($uninstall, 'if (is_multisite()) { return; }'), 'multisite uninstall is non-destructive');
expect(str_contains($uninstall, "'digiforge_db_schema_version'"), 'schema version cleanup is present');
echo "DigiForge foundation tests passed.\n";
