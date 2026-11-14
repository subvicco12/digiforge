<?php

declare(strict_types=1);

$testsDirectory = getenv('WP_TESTS_DIR');
if (! is_string($testsDirectory) || $testsDirectory === '') {
    throw new RuntimeException('Set WP_TESTS_DIR to the WordPress PHPUnit test-library directory.');
}

if (! defined('DIGIFORGE_CREDENTIAL_KEY')) {
    define('DIGIFORGE_CREDENTIAL_KEY', str_repeat('digiforge-test-key-', 2));
}

// PHPUnit transactions can roll back WordPress's generated salt options while
// wp_salt() keeps their values cached in the parent. Independent CLI workers
// must authenticate the same synthetic cookies and journal seals after rollback.
// These public fixture values are loaded only by this test bootstrap.
foreach (['AUTH', 'SECURE_AUTH', 'LOGGED_IN', 'NONCE'] as $scheme) {
    foreach (['KEY', 'SALT'] as $part) {
        $name = $scheme . '_' . $part;
        if (! defined($name)) {
            define($name, hash('sha256', 'digiforge-wordpress-phpunit-only-' . $name));
        }
    }
}

define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname(__DIR__, 2) . '/vendor/yoast/phpunit-polyfills');

require_once rtrim($testsDirectory, '/\\') . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__, 2) . '/digiforge.php';
});

// Retained custody is intentionally immutable. A fresh database must never reuse
// bytes deliberately tampered by a previous PHPUnit process with recycled IDs.
$GLOBALS['wp_tests_options']['upload_path'] ??= sys_get_temp_dir()
    . '/digiforge-wp-uploads-' . getmypid() . '-' . bin2hex(random_bytes(8));

require rtrim($testsDirectory, '/\\') . '/includes/bootstrap.php';
require_once __DIR__ . '/Support/PrintifyTemplateFixture.php';
