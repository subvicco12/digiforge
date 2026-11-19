<?php
/**
 * Plugin Name: DigiForge Printify API
 * Description: Passive standalone Printify API engineering candidate.
 * Version: 0.1.0
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
