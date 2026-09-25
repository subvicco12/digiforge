<?php

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols
if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0): string|false
    {
        return json_encode($value, $flags);
    }
}
if (! function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        $key = strtolower($key);
        return preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';
    }
}
if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        $value = strip_tags($value);
        $value = preg_replace('/[\r\n\t ]+/', ' ', $value) ?? '';
        return trim($value);
    }
}
// phpcs:enable PSR1.Files.SideEffects.FoundWithSymbols

require_once __DIR__ . '/../../includes/Core/Config.php';
require_once __DIR__ . '/../../includes/ProductFactory/Lifecycle.php';
require_once __DIR__ . '/../../includes/ProductFactory/Workflow.php';
require_once __DIR__ . '/../../includes/ProductFactory/PortfolioProjection.php';
require_once __DIR__ . '/../../includes/ProductFactory/PortfolioAdminSummary.php';
require_once __DIR__ . '/../../includes/ProductFactory/ProductDefinitionContract.php';
require_once __DIR__ . '/../../includes/ProductFactory/AutomatedQa.php';
require_once __DIR__ . '/../../includes/ProductFactory/BatchQaProjection.php';
require_once __DIR__ . '/../../includes/DigitalFactory/Lifecycle.php';
require_once __DIR__ . '/../../includes/AI/Lifecycle.php';
require_once __DIR__ . '/../../includes/Listings/Lifecycle.php';
require_once __DIR__ . '/../../includes/Listings/Validator.php';
require_once __DIR__ . '/../../includes/Listings/BatchListingProjection.php';
require_once __DIR__ . '/../../includes/Listings/AdminStateSummary.php';
require_once __DIR__ . '/../../includes/DigitalFactory/Validator.php';
require_once __DIR__ . '/../../includes/DigitalFactory/QaAdminSummary.php';
require_once __DIR__ . '/../../includes/Orders/Lifecycle.php';
require_once __DIR__ . '/../../includes/Orders/Validator.php';
require_once __DIR__ . '/../../includes/Finance/Lifecycle.php';
require_once __DIR__ . '/../../includes/Finance/Validator.php';
require_once __DIR__ . '/../../includes/Operations/RetentionPolicy.php';
require_once __DIR__ . '/../../includes/Operations/RecoveryDrill.php';
require_once __DIR__ . '/../../includes/Database/MigrationPlan.php';
require_once __DIR__ . '/../../includes/POD/BusinessScope.php';
require_once __DIR__ . '/../../includes/Queue/JobState.php';
require_once __DIR__ . '/../../includes/Observability/HealthStatus.php';
