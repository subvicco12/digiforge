<?php
declare(strict_types=1);
/** Isolated actual-core acceptance only. No production config, credentials or provider networking. */
require '/private/core10-fixture-configuration.php';
$c=DigiForgeTestFixtureConfiguration::prepare('/companionrepo/tests/contracts/native-isolated-config.php');define('WP_TESTS_CONFIG_FILE_PATH',$c['path']);
require_once '/wp-tests/includes/functions.php';
tests_add_filter('muplugins_loaded',static function():void{add_filter('pre_http_request',static fn($p)=>$p!==false?$p:new WP_Error('isolated_http_denied'),PHP_INT_MAX);add_filter('pre_wp_mail',static fn()=>false,PHP_INT_MAX);},1);
if(getenv('DF_CORE_WITH_COMPANION')==='1')tests_add_filter('muplugins_loaded',static function():void{require '/companion/digiforge-printify-connector.php';interface_exists(DigiForgePrintifyConnector\Preparation\NativeCorePortInterface::class);},11);
require '/feature/tests/wordpress/bootstrap.php';
