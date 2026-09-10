<?php
declare(strict_types=1);
// Lightweight Product Factory architecture checks; no WordPress runtime is required.
require __DIR__ . '/../includes/ProductFactory/Lifecycle.php';
use DigiForge\ProductFactory\Lifecycle;
function pf_expect(bool $condition, string $message): void { if (! $condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
function pf_source(string $path): string { return (string) file_get_contents(__DIR__ . '/../' . $path); }

pf_expect(Lifecycle::valid('opportunity', 'NEW'), 'opportunity state is valid');
pf_expect(Lifecycle::can_transition('opportunity', 'NEW', 'QUALIFIED'), 'opportunity can qualify');
pf_expect(! Lifecycle::can_transition('opportunity', 'ARCHIVED', 'NEW'), 'archived opportunity cannot reopen');
pf_expect(Lifecycle::can_transition('product', 'READY', 'ACTIVE'), 'ready product can activate');
pf_expect(! Lifecycle::can_transition('product', 'DRAFT', 'ACTIVE'), 'draft product cannot activate');
pf_expect(Lifecycle::can_transition('product_version', 'APPROVED', 'RELEASED'), 'approved version can release');
pf_expect(! Lifecycle::can_transition('product_version', 'DRAFT', 'RELEASED'), 'draft version cannot release');
$migration = pf_source('includes/Database/Migrator.php');
foreach (['opportunities()', 'product_families()', 'products()', 'product_versions()', 'opportunity_status', 'family_status', 'product_version'] as $needle) { pf_expect(str_contains($migration, $needle), "schema contains $needle"); }
$tables = pf_source('includes/Database/Tables.php');
foreach (['opportunities', 'product_families', 'products', 'product_versions'] as $table) { pf_expect(str_contains($tables, "'$table'"), "table helper for $table exists"); }
$repo = pf_source('includes/ProductFactory/Repository.php');
foreach (['sanitize_text_field', 'sanitize_textarea_field', 'sanitize_metadata', 'digiforge_invalid_relationship', 'digiforge_invalid_transition', 'Logger::audit', 'product_version_released'] as $needle) { pf_expect(str_contains($repo, $needle), "repository includes $needle"); }
pf_expect(str_contains(pf_source('includes/Queue/JobRepository.php'), "'BLOCKED'"), 'job boundary defaults to BLOCKED');
pf_expect(str_contains($repo, 'parent_exists'), 'relationships are validated');
$rest = pf_source('includes/REST/Controller.php');
foreach (['opportunities', 'product-families', 'product-versions', 'manage_digiforge_products', 'Idempotency', 'Idempotency-Key'] as $needle) { pf_expect(str_contains($rest, $needle), "REST includes $needle"); }
$admin = pf_source('includes/Core/Admin.php');
foreach (['digiforge-products', 'digiforge-product-families', 'digiforge-opportunities'] as $needle) { pf_expect(str_contains($admin, $needle), "admin page $needle exists"); }
$config = pf_source('includes/Core/Config.php');
pf_expect(str_contains($config, "'product_development'"), 'product development automation is explicit and default OFF');
echo "DigiForge Product Factory tests passed.\n";
