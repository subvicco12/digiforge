<?php
/**
 * Plugin Name: DigiForge
 * Description: Secure operational foundation for a WordPress-native product business platform.
 * Version: 0.13.3
 * Requires at least: 7.1
 * Requires PHP: 8.3
 * Author: DigiForge
 * Text Domain: digiforge
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

const DIGIFORGE_VERSION = '0.13.3';
const DIGIFORGE_FILE = __FILE__;
const DIGIFORGE_PATH = __DIR__ . '/';
define('DIGIFORGE_URL', plugin_dir_url(__FILE__));
const DIGIFORGE_DB_VERSION = '13';

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
spl_autoload_register('digiforge_autoload');

\DigiForge\Database\DbDeltaCompatibility::register();

function digiforge(): \DigiForge\Core\Plugin {
    return \DigiForge\Core\Plugin::instance();
}

add_action('wp_enqueue_scripts', static function (): void {
    if (is_admin()) {