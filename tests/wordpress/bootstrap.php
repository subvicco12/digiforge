<?php
declare(strict_types=1);

$testsDirectory = getenv('WP_TESTS_DIR');
if (! is_string($testsDirectory) || $testsDirectory === '') {
    throw new RuntimeException('Set WP_TESTS_DIR to the WordPress PHPUnit test-library directory.');
}

require_once rtrim($testsDirectory, '/\\') . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__, 2) . '/digiforge.php';
});

require rtrim($testsDirectory, '/\\') . '/includes/bootstrap.php';
