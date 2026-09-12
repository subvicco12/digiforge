<?php

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols
if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0): string|false
    {
        return json_encode($value, $flags);
    }
}
// phpcs:enable PSR1.Files.SideEffects.FoundWithSymbols

require_once __DIR__ . '/../../includes/Core/Config.php';
require_once __DIR__ . '/../../includes/ProductFactory/Lifecycle.php';
require_once __DIR__ . '/../../includes/DigitalFactory/Lifecycle.php';
require_once __DIR__ . '/../../includes/AI/Lifecycle.php';
require_once __DIR__ . '/../../includes/Listings/Lifecycle.php';
require_once __DIR__ . '/../../includes/Listings/Validator.php';
require_once __DIR__ . '/../../includes/Database/MigrationPlan.php';
require_once __DIR__ . '/../../includes/Queue/JobState.php';
require_once __DIR__ . '/../../includes/Observability/HealthStatus.php';
