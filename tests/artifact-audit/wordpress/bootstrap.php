<?php
if(!defined('DIGIFORGE_CREDENTIAL_KEY'))define('DIGIFORGE_CREDENTIAL_KEY',str_repeat('disposable-test-key-',2));
define('WP_TESTS_PHPUNIT_POLYFILLS_PATH',dirname(__DIR__,3).'/vendor/yoast/phpunit-polyfills');
require getenv('WP_TESTS_DIR').'/includes/functions.php';
tests_add_filter('muplugins_loaded',static function(){add_filter('pre_http_request',static fn()=>new WP_Error('artifact_audit_no_external_http'),PHP_INT_MAX);require getenv('EXACT_PLUGIN_ROOT').'/digiforge.php';});
require getenv('WP_TESTS_DIR').'/includes/bootstrap.php';
