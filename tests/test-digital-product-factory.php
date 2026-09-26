<?php

declare(strict_types=1);

require __DIR__ . '/../includes/DigitalFactory/Lifecycle.php';
require __DIR__ . '/../includes/Core/Config.php';

use DigiForge\DigitalFactory\Lifecycle;
use DigiForge\Core\Config;

function df_expect(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

function df_source(string $path): string
{
    return (string) file_get_contents(__DIR__ . '/../' . $path);
}

df_expect(Lifecycle::can_transition('DRAFT', 'FILES_PENDING'), 'digital workflow starts with files');
df_expect(Lifecycle::can_transition('QA_PENDING', 'QA_FAILED'), 'technical QA may fail');
df_expect(! Lifecycle::can_transition('DRAFT', 'PUBLISH_READY'), 'workflow cannot skip QA and approval');
df_expect(count(Lifecycle::readiness()) === 12, 'all readiness gates are represented');

$readiness = Lifecycle::readiness();
$readiness = Lifecycle::advance_readiness($readiness, 'QA_PENDING');
df_expect($readiness['files'] === true && $readiness['technical_qa'] === false, 'QA pending marks files ready only');
$readiness = Lifecycle::advance_readiness($readiness, 'QA_PASSED');
df_expect($readiness['technical_qa'] === true, 'QA pass synchronizes technical readiness');
$readiness = Lifecycle::advance_readiness($readiness, 'VISUAL_REVIEW');
df_expect($readiness['content_qa'] === true, 'visual review synchronizes completed content QA');
$readiness = Lifecycle::advance_readiness($readiness, 'QA_FAILED');
df_expect($readiness['technical_qa'] === false, 'QA failure clears technical readiness');

$migration = df_source('includes/Database/Migrator.php');
foreach (['digital_products()', 'digital_files()', 'digital_file_versions()', 'digital_packages()', 'digital_previews()', 'digital_templates()', 'digital_licenses()', 'digital_download_checks()'] as $table) {
    df_expect(str_contains($migration, $table), "$table schema exists");
}
foreach (['UNIQUE KEY product_version', 'UNIQUE KEY file_version', 'UNIQUE KEY idempotency_key', 'checksum_sha256', 'validation_result', 'failure_reason', 'review_status'] as $rule) {
    df_expect(str_contains($migration, $rule), "$rule schema contract exists");
}
df_expect(str_contains($migration, 'MigrationPlan::pending'), 'ordered schema migration plan is installed');
df_expect(str_contains($migration, 'Capabilities::addDigital()'), 'versioned upgrade grants the digital capability');

$capabilities = df_source('includes/Core/Capabilities.php');
df_expect(str_contains($capabilities, 'public static function addDigital()'), 'targeted digital capability helper exists');
df_expect(str_contains($capabilities, "add_cap('manage_digiforge_digital')"), 'targeted helper grants digital capability');
df_expect(str_contains($capabilities, 'foreach (self::ALL as $cap)'), 'fresh activation retains full DigiForge capability grant');

$repository = df_source('includes/DigitalFactory/Repository.php');
foreach (['invalid_relationship', 'Product version must belong to the product', 'Preview must match the product and file', 'QA target must belong', 'idempotent_replay', 'find_by_key', 'Logger::audit', 'LIMIT %d OFFSET %d', 'MAX_PAGE_SIZE = 100', 'license_code_hash', 'validate_descendants'] as $rule) {
    df_expect(str_contains($repository, $rule), "repository provides $rule");
}
df_expect(! str_contains($repository, 'wp_remote_'), 'digital repository has no external HTTP client');
df_expect(! str_contains($repository, 'curl_init'), 'digital repository has no curl execution');

$validator = df_source('includes/DigitalFactory/Validator.php');
foreach (['pdf_integrity', 'pdf_page_count', 'pdf_dimensions', 'pdf_resolution', 'pdf_fonts', 'pdf_rendering', 'pdf_blank_pages', 'pdf_links', 'pdf_file_size', 'image_dimensions', 'image_transparency', 'image_corruption', 'archive_integrity', 'archive_required_files', 'archive_folder_structure', 'archive_file_naming', 'archive_package_size', 'template_reference', 'template_access_instructions', 'template_preview_relationship', 'preview_relationship', 'checksum', 'package_generation'] as $check) {
    df_expect(str_contains($validator, $check), "$check is supported");
}

$api = df_source('includes/REST/DigitalFactoryController.php');
foreach (['digital-products', 'digital-files', 'digital-file-versions', 'digital-packages', 'digital-previews', 'digital-templates', 'digital-licenses', 'digital-download-checks', 'permission_callback', 'manage_digiforge_digital', 'Idempotency-Key', 'X-WP-Total', 'X-WP-TotalPages'] as $value) {
    df_expect(str_contains($api, $value), "REST exposes $value");
}

$admin = df_source('includes/DigitalFactory/Admin.php');
foreach (['Digital Products', 'Digital Files', 'Digital Packages', 'Digital Templates', 'Digital Licenses', 'Digital QA / Download Checks', 'manage_digiforge_digital'] as $value) {
    df_expect(str_contains($admin, $value), "admin exposes $value");
}

$bootstrap = df_source('digiforge.php');
df_expect(str_contains($bootstrap, '* Version: 1.0.39') && str_contains($bootstrap, "DIGIFORGE_VERSION = '1.0.39'") && str_contains($bootstrap, "DIGIFORGE_DB_VERSION = '14'"), 'versions synchronized');

$defaults = Config::default_settings();
df_expect($defaults['stop_all'] === true && $defaults['automation_armed'] === false && $defaults['activation_authorized'] === false, 'automation safety defaults are fail-closed');
foreach (Config::SWITCHES as $switch) {
    if ($switch !== 'stop_all') {
        df_expect($defaults[$switch] === false, "$switch remains OFF");
    }
}

echo "DigiForge Digital Product Factory tests passed.\n";
