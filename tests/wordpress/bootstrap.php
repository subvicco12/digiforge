<?php

declare(strict_types=1);

$testsDirectory = getenv('WP_TESTS_DIR');
if (! is_string($testsDirectory) || $testsDirectory === '') {
    throw new RuntimeException('Set WP_TESTS_DIR to the WordPress PHPUnit test-library directory.');
}

if (! defined('DIGIFORGE_CREDENTIAL_KEY')) {
    define('DIGIFORGE_CREDENTIAL_KEY', str_repeat('digiforge-test-key-', 2));
}

define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname(__DIR__, 2) . '/vendor/yoast/phpunit-polyfills');

// WP's installer resets core tables only; plugin DDL/committed fixtures survive reused DBs.
// Isolate the namespace instead of deleting plugin evidence or resetting operation records.
require_once __DIR__ . '/FixtureConfiguration.php';
if (!defined('WP_TESTS_CONFIG_FILE_PATH')) {
    $configuration = DigiForgeTestFixtureConfiguration::resolve(rtrim($testsDirectory, '/\\') . '/wp-tests-config.php');
    define('WP_TESTS_CONFIG_FILE_PATH', $configuration['path']);
}

require_once rtrim($testsDirectory, '/\\') . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__, 2) . '/digiforge.php';
});

require rtrim($testsDirectory, '/\\') . '/includes/bootstrap.php';
