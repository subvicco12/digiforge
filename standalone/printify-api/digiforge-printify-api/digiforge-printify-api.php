<?php
/**
 * Plugin Name: DigiForge Printify API
 * Description: Standalone Printify diagnostics and offline catalog validation.
 * Version: 0.4.0
 * Requires at least: 7.1
 * Requires PHP: 8.3
 * Author: DigiForge
 * Text Domain: digiforge-printify-api
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/Status.php';

if (!class_exists(\DigiForgePrintifyApi\Runtime::class, false)) {
    require_once __DIR__ . '/Runtime.php';
    \DigiForgePrintifyApi\Runtime::register();
}
