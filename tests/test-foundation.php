<?php

declare(strict_types=1);

require __DIR__ . '/../includes/Core/Config.php';
require __DIR__ . '/../includes/Queue/JobState.php';

use DigiForge\Core\Config;
use DigiForge\Queue\JobState;

function expect(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

function source(string $path): string
{
    return (string) file_get_contents(__DIR__ . '/../' . $path);
}

$defaults = Config::default_settings();
expect($defaults['stop_all'] === true, 'STOP ALL must default to ON');
expect($defaults['automation_armed'] === false, 'automation must default to unarmed');
expect($defaults['activation_authorized'] === false, 'activation authorization must default to false');
expect(! Config::writable_setting('automation_armed'), 'arming cannot be changed through ordinary settings');
expect(! Config::writable_setting('activation_authorized'), 'activation authorization cannot be changed through ordinary settings');
expect(! Config::valid_value('stop_all', '1'), 'boolean settings reject string coercion');

foreach (['QUEUED', 'RUNNING', 'WAITING', 'RETRY', 'SUCCESS', 'FAILED', 'BLOCKED', 'CANCELLED', 'HUMAN_REVIEW', 'DEAD_LETTER'] as $state) {
    expect(JobState::valid($state), "$state is valid");
}
expect(JobState::terminal('SUCCESS'), 'success is terminal');
expect(! JobState::terminal('RUNNING'), 'running is non-terminal');

$capabilities = source('includes/Core/Capabilities.php');
foreach (['manage_digiforge', 'manage_digiforge_products', 'manage_digiforge_digital', 'manage_digiforge_research', 'manage_digiforge_ai', 'manage_digiforge_production', 'manage_digiforge_pod', 'manage_digiforge_listings', 'manage_digiforge_orders', 'manage_digiforge_finance', 'manage_digiforge_automation', 'manage_digiforge_connections', 'manage_digiforge_settings', 'publish_digiforge', 'view_digiforge_analytics'] as $capability) {
    expect(str_contains($capabilities, "'$capability'"), "$capability capability declared");
}

$bootstrap = source('digiforge.php');
expect(str_contains($bootstrap, "spl_autoload_register('digiforge_autoload')"), 'internal autoloader is registered');
expect(str_contains($bootstrap, 'register_activation_hook'), 'activation hook is registered');
expect(str_contains($bootstrap, "DIGIFORGE_DB_VERSION = '14'"), 'database schema version is current');
expect(str_contains($bootstrap, '* Version: 1.0.35'), 'plugin header release version is current');
expect((bool) preg_match("/const\\s+DIGIFORGE_VERSION\\s*=\\s*'1\\.0\\.34'\\s*;/", $bootstrap), 'runtime release version is current');

$settings = source('includes/Core/Settings.php');
expect(str_contains($settings, "get('activation_authorized', false) === true"), 'effective switches require activation authorization');
expect(str_contains($settings, "get('automation_armed', false) === true"), 'effective switches require automation arming');
expect(str_contains($settings, "get('stop_all', true) === false"), 'effective switches require STOP ALL off');

$controller = source('includes/REST/Controller.php');
expect(str_contains($controller, "'/readiness'"), 'authenticated readiness endpoint is registered');
expect(str_contains($controller, 'new Readiness()'), 'readiness endpoint uses deterministic readiness service');

$readiness = source('includes/Operations/Readiness.php');
foreach (['READY_LOCKED', 'activation_not_authorized', 'automation_unarmed', 'no_effective_feature_switches', 'retention_fail_closed', 'recovery_drill_available', 'external_actions_performed'] as $required) {
    expect(str_contains($readiness, $required), "readiness report includes $required");
}
expect(! str_contains($readiness, 'wp_remote_'), 'readiness service has no external HTTP client');
expect(! str_contains($readiness, 'curl_init'), 'readiness service has no curl execution');

foreach ([
    ['includes/Database/ProductionSchema.php', '$currentVersion === 8'],
    ['includes/Database/PodSchema.php', '$currentVersion === 9'],
    ['includes/Database/ListingSchema.php', '$currentVersion === 10'],
    ['includes/Database/OrderSchema.php', '$currentVersion === 11'],
    ['includes/Database/FinanceSchema.php', '$currentVersion === 12'],
] as [$file, $guard]) {
    expect(str_contains(source($file), $guard), "$file exact additive migration guard exists");
}

$orderSchema = source('includes/Database/OrderSchema.php');
foreach (['orders', 'order_line_items', 'personalization_submissions', 'fulfillment_plans', 'fulfillment_intents', 'fulfillment_readiness_reviews'] as $table) {
    expect(str_contains($orderSchema, "Tables::$table()"), "$table Batch 9 table declared");
}

$financeSchema = source('includes/Database/FinanceSchema.php');
foreach (['finance_ledger', 'fx_snapshots', 'tax_classifications', 'finance_periods', 'analytics_snapshots', 'operational_alerts', 'finance_intents'] as $table) {
    expect(str_contains($financeSchema, "Tables::$table()"), "$table Batch 10 table declared");
}

$orderLifecycle = source('includes/Orders/Lifecycle.php');
foreach (['PREPARE_ORDER', 'PREPARE_FULFILLMENT', 'PREPARE_PERSONALIZATION', 'PREPARE_SHIPPING', 'PREPARE_CANCELLATION', 'PREPARE_REFUND_REVIEW'] as $intent) {
    expect(str_contains($orderLifecycle, "'$intent'"), "$intent inert intent declared");
}
foreach (['QUEUED', 'EXECUTING', 'SUBMITTED', 'PROCESSING', 'FULFILLED', 'SHIPPED', 'REFUNDED', 'SYNCED'] as $forbidden) {
    expect(! str_contains($orderLifecycle, "'$forbidden'"), "$forbidden is absent from Batch 9 fulfillment lifecycle");
}

$orderController = source('includes/REST/OrderController.php');
expect(str_contains($orderController, 'manage_digiforge_orders'), 'order REST requires order capability');
expect(str_contains($orderController, 'Idempotency-Key'), 'order mutations require idempotency');
expect(str_contains($orderController, 'MAX_BODY_BYTES'), 'order mutations enforce request body bounds');
$orderRepository = source('includes/Orders/Repository.php');
expect((bool) preg_match("/'state'\\s*=>\\s*'BLOCKED'/", $orderRepository), 'fulfillment intents are inert and blocked');
expect(str_contains($orderRepository, 'Logger::audit'), 'order mutations are audited');
expect(! str_contains($orderRepository, 'wp_remote_'), 'order repository has no external HTTP client');
expect(! str_contains($orderRepository, 'curl_init'), 'order repository has no curl execution');

$financeController = source('includes/REST/FinanceController.php');
expect(str_contains($financeController, 'manage_digiforge_finance'), 'finance REST requires finance capability');
expect(str_contains($financeController, 'Idempotency-Key'), 'finance mutations require idempotency');
expect(str_contains($financeController, 'MAX_BODY_BYTES'), 'finance mutations enforce request body bounds');
$financeRepository = source('includes/Finance/Repository.php');
expect((bool) preg_match("/'state'\\s*=>\\s*'BLOCKED'/", $financeRepository), 'finance intents are inert and blocked');
expect(str_contains($financeRepository, 'Logger::audit'), 'finance mutations are audited');
expect(! str_contains($financeRepository, 'wp_remote_'), 'finance repository has no external HTTP client');
expect(! str_contains($financeRepository, 'curl_init'), 'finance repository has no curl execution');

$plugin = source('includes/Core/Plugin.php');
expect(str_contains($plugin, 'OrderController'), 'order REST service is wired');
expect(str_contains($plugin, '\\DigiForge\\Orders\\Admin'), 'order admin service is wired');
expect(str_contains($plugin, 'FinanceController'), 'finance REST service is wired');
expect(str_contains($plugin, '\\DigiForge\\Finance\\Admin'), 'finance admin service is wired');

$logger = source('includes/Security/Logger.php');
foreach (['password', 'secret', 'token', 'accesstoken', 'refreshtoken', 'clientsecret', 'authorization', 'apikey', 'credential', 'privatekey', 'signingkey'] as $credentialKey) {
    expect(str_contains($logger, "'$credentialKey'"), "$credentialKey redaction rule declared");
}

$uninstall = source('uninstall.php');
expect(str_contains($uninstall, 'if (is_multisite()) { return; }'), 'multisite uninstall is non-destructive');
expect(str_contains($uninstall, "'digiforge_db_schema_version'"), 'schema version cleanup is present');

echo "DigiForge foundation tests passed.\n";
