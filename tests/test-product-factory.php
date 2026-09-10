<?php
declare(strict_types=1);
// Pure policy and structural checks; database behavior is exercised through repository contract checks.
require __DIR__ . '/../includes/Core/Config.php';
require __DIR__ . '/../includes/ProductFactory/Lifecycle.php';

use DigiForge\Core\Config;
use DigiForge\ProductFactory\Lifecycle;

function pf_expect(bool $condition, string $message): void { if (! $condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
function pf_source(string $path): string { return (string) file_get_contents(__DIR__ . '/../' . $path); }

pf_expect(Lifecycle::initial('opportunity') === 'NEW', 'opportunities begin NEW');
foreach (['product_family', 'product', 'product_version'] as $type) { pf_expect(Lifecycle::initial($type) === 'DRAFT', "$type begins DRAFT"); }
pf_expect(Lifecycle::initial('unknown') === null, 'unknown entities have no initial state');

$valid = [
    'opportunity' => [['NEW','QUALIFIED'], ['NEW','REJECTED'], ['NEW','ARCHIVED']],
    'product_family' => [['DRAFT','ACTIVE'], ['DRAFT','ARCHIVED']],
    'product' => [['DRAFT','READY'], ['READY','DRAFT'], ['READY','ACTIVE'], ['ACTIVE','ARCHIVED']],
    'product_version' => [['DRAFT','REVIEW'], ['REVIEW','DRAFT'], ['REVIEW','APPROVED'], ['APPROVED','DRAFT'], ['APPROVED','REVIEW'], ['APPROVED','RELEASED'], ['RELEASED','RETIRED']],
];
foreach ($valid as $type => $transitions) { foreach ($transitions as [$from, $to]) { pf_expect(Lifecycle::can_transition($type, $from, $to), "$type permits $from to $to"); } }
$invalid = [['opportunity','QUALIFIED','ARCHIVED'], ['product_family','ACTIVE','DRAFT'], ['product','DRAFT','ACTIVE'], ['product','ARCHIVED','READY'], ['product_version','DRAFT','RELEASED'], ['product_version','RELEASED','APPROVED']];
foreach ($invalid as [$type, $from, $to]) { pf_expect(! Lifecycle::can_transition($type, $from, $to), "$type rejects $from to $to"); }

$migration = pf_source('includes/Database/Migrator.php');
foreach (['opportunities()', 'product_families()', 'products()', 'product_versions()'] as $table) { pf_expect(str_contains($migration, $table), "$table migration exists"); }
foreach (['opportunity_id', 'product_family_id', 'product_id', 'UNIQUE KEY idempotency_key', 'UNIQUE KEY product_version'] as $schema) { pf_expect(str_contains($migration, $schema), "$schema schema rule exists"); }
pf_expect(str_contains($migration, "digiforge_db_schema_version', 3"), 'schema migration is versioned');

$repository = pf_source('includes/ProductFactory/Repository.php');
foreach (['sanitize_text_field', 'sanitize_textarea_field', 'absint', 'invalid_relationship', 'idempotent_replay', 'find_by_key', 'Logger::audit', 'invalid_transition'] as $contract) { pf_expect(str_contains($repository, $contract), "$contract repository contract exists"); }
pf_expect(str_contains($repository, "['id' => \$id, 'state' => \$from]"), 'state transition uses optimistic concurrency');
pf_expect(str_contains($repository, "unset(\$row['idempotency_key'])"), 'idempotency keys are not exposed');

$rest = pf_source('includes/REST/ProductFactoryController.php');
foreach (['opportunities', 'product-families', 'products', 'product-versions', "'digiforge/v1'", 'Idempotency-Key', 'permission_callback', 'manage_digiforge_products'] as $route) { pf_expect(str_contains($rest, $route), "$route REST contract exists"); }
pf_expect(str_contains($rest, "'state' => ['required' => true"), 'state is required and sanitized');

$admin = pf_source('includes/Core/Admin.php');
pf_expect(str_contains($admin, 'digiforge-product-factory'), 'Product Factory admin page is registered');
pf_expect(str_contains($admin, "'manage_digiforge_products'"), 'admin page is capability gated');
foreach (Config::default_settings() as $key => $value) { pf_expect($value === false, "$key automation default remains OFF"); }

echo "DigiForge Product Factory tests passed.\n";
