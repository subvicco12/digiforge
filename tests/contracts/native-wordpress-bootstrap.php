<?php
declare(strict_types=1);
/** Isolated composition only. Preserve the actual core bootstrap and fixture initialization. */
require '/feature/tests/wordpress/FixtureConfiguration.php';
$config=DigiForgeTestFixtureConfiguration::prepare('/private/native-test-config.php');
define('WP_TESTS_CONFIG_FILE_PATH',$config['path']);
define('DIGIFORGE_CREDENTIAL_KEY',str_repeat('synthetic-test-key-',2));
require_once '/wp-tests/includes/functions.php';
tests_add_filter('muplugins_loaded',static function():void {
 add_filter('pre_http_request',static fn($pre)=>$pre!==false?$pre:new WP_Error('isolated_no_http'),PHP_INT_MAX);
 add_filter('pre_wp_mail',static fn()=>false,PHP_INT_MAX);
},1);
tests_add_filter('muplugins_loaded',static function():void {
 require '/feature/companion/digiforge-printify-connector/digiforge-printify-connector.php';
 interface_exists('DigiForgePrintifyConnector\\Preparation\\NativeCorePortInterface');
},11);
require '/feature/tests/wordpress/bootstrap.php';
echo 'ISOLATED_PREFIX='.WP_TESTS_TABLE_PREFIX.PHP_EOL;
