<?php
declare(strict_types=1);

if (! defined('AUTH_KEY')) { define('AUTH_KEY', 'digiforge-test-auth-key-abcdefghijklmnopqrstuvwxyz'); }
if (! defined('SECURE_AUTH_KEY')) { define('SECURE_AUTH_KEY', 'digiforge-test-secure-auth-key-abcdefghijklmnopqrstuvwxyz'); }
if (! defined('LOGGED_IN_SALT')) { define('LOGGED_IN_SALT', 'digiforge-test-logged-in-salt-abcdefghijklmnopqrstuvwxyz'); }
require __DIR__ . '/../includes/Integrations/CredentialVault.php';

use DigiForge\Integrations\CredentialVault;

function if_expect(bool $condition, string $message): void { if (! $condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
function if_source(string $path): string { return (string) file_get_contents(__DIR__ . '/../' . $path); }

$plain = 'test-secret-value-not-for-production';
$cipher = CredentialVault::encrypt($plain);
if_expect($cipher !== $plain, 'credential vault never stores plaintext');
if_expect(CredentialVault::decrypt($cipher) === $plain, 'credential vault round-trips encrypted values');
if_expect(strlen(CredentialVault::fingerprint($plain)) === 16, 'credential fingerprints are short non-secret identifiers');

$tables = if_source('includes/Database/Tables.php');
if_expect(str_contains($tables, 'integrations()') && str_contains($tables, 'integration_secrets()'), 'integration table names are registered');
$migrator = if_source('includes/Database/Migrator.php');
foreach (['provider_connection','integration_secret','ciphertext','fingerprint',"digiforge_db_schema_version', 5"] as $rule) { if_expect(str_contains($migrator, $rule), "migration includes $rule"); }
if_expect(str_contains($migrator, 'if ($schema_version < 4) { Capabilities::addDigital(); }'), 'schema 5 migration does not re-grant capabilities on already-migrated sites');

$repository = if_source('includes/Integrations/Repository.php');
foreach (['etsy','printify','gelato','ai','DISCONNECTED','CONFIGURED','PAUSED','ERROR','CredentialVault::encrypt','secret_in_config','Logger::audit'] as $rule) { if_expect(str_contains($repository, $rule), "integration repository includes $rule"); }
if_expect(!str_contains($repository, 'CredentialVault::decrypt'), 'normal repository responses cannot retrieve plaintext secrets');

$rest = if_source('includes/REST/IntegrationsController.php');
foreach (['/integrations','/secrets/','manage_digiforge_connections','permission_callback','X-WP-Total','X-WP-TotalPages'] as $rule) { if_expect(str_contains($rest, $rule), "integration REST API includes $rule"); }
$admin = if_source('includes/Integrations/Admin.php');
if_expect(str_contains($admin, 'DigiForge Connections') && str_contains($admin, 'manage_digiforge_connections'), 'Connections admin page is capability gated');

$config = if_source('includes/Core/Config.php');
if_expect(!str_contains($config, '=> true'), 'all production automation switches remain OFF');
$bootstrap = if_source('digiforge.php');
if_expect(str_contains($bootstrap, "DIGIFORGE_VERSION = '0.4.0'") && str_contains($bootstrap, "DIGIFORGE_DB_VERSION = '5'"), 'plugin and schema versions are synchronized');

foreach (['includes/Integrations/CredentialVault.php','includes/Integrations/Repository.php','includes/REST/IntegrationsController.php','includes/Integrations/Admin.php'] as $path) {
    $source = if_source($path);
    if_expect(!preg_match('/wp_remote_(get|post|request|head)|curl_init|GuzzleHttp|Requests\\\\/', $source), "$path performs no provider network calls");
}

echo "DigiForge Integrations Foundation tests passed.\n";
