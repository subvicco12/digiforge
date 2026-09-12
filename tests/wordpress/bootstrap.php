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

require_once rtrim($testsDirectory, '/\\') . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__, 2) . '/digiforge.php';
});

require rtrim($testsDirectory, '/\\') . '/includes/bootstrap.php';
