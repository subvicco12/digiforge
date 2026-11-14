<?php
/**
 * Plugin Name: DigiForge Printify Read Connector
 * Description: Thin GET-only adapter to the DigiForge-owned provider interface. No credentials or independent execution authority.
 * Version: 0.7.1
 * Requires at least: 7.1
 * Requires PHP: 8.3
 * Requires Plugins: digiforge
 * Text Domain: digiforge-printify-connector
 */
declare(strict_types=1);
namespace DigiForgePrintifyConnector;
if(!defined('ABSPATH')){exit;}
require_once __DIR__.'/autoload.php';
require_once __DIR__.'/Plugin.php';
Plugin::register();
