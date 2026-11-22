<?php
declare(strict_types=1);
define('ABSPATH', '/opt/shop2/wordpress-core/');
define('DB_NAME', 'digiforge_tests');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_HOST', 'localhost:/work/db.sock');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('WP_TESTS_DOMAIN', 'example.invalid');
define('WP_TESTS_EMAIL', 'synthetic@example.invalid');
define('WP_TESTS_TITLE', 'Disposable OCI fixture');
define('WP_PHP_BINARY', '/usr/bin/php');
define('WP_DEBUG', true);
define('WP_ENVIRONMENT_TYPE', 'local');
define('WP_TEMP_DIR', '/work/');
$table_prefix = 'wptests_';
