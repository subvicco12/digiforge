<?php
declare(strict_types=1);
// Credentials remain in the existing private local fixture configuration, never this repository.
require '/private/native-test-config.php';
require_once '/companionrepo/tests/contracts/NativeAcceptanceFixtureSalt.php';
if(!defined('DF_NATIVE_FIXTURE_BOOTSTRAP'))define('DF_NATIVE_FIXTURE_BOOTSTRAP','/companionrepo/tests/contracts/native-isolated-bootstrap.php');
// The install-only subprocess loads config without the test hook library; it performs no authentication.
if(function_exists('tests_add_filter'))tests_add_filter('muplugins_loaded',static function():void {
 add_filter('salt',static fn(string $base,string $scheme):string=>NativeAcceptanceFixtureSalt::derive($base,$scheme,WP_TESTS_TABLE_PREFIX),PHP_INT_MIN,2);
},PHP_INT_MIN);
