<?php
/**
 * Plugin Name: DigiForge
 * Description: Secure operational foundation for a WordPress-native product business platform.
 * Version: 0.8.0
 * Requires at least: 7.1
 * Requires PHP: 8.3
 * Author: DigiForge
 * Text Domain: digiforge
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

const DIGIFORGE_VERSION = '0.8.0';
const DIGIFORGE_FILE = __FILE__;
const DIGIFORGE_PATH = __DIR__ . '/';
define('DIGIFORGE_URL', plugin_dir_url(__FILE__));
const DIGIFORGE_DB_VERSION = '11';

/** @return void */
function digiforge_autoload(string $class): void {
    $prefix = 'DigiForge\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = DIGIFORGE_PATH . 'includes/' . $relative . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
}