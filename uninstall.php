<?php
/** Uninstall only removes business data when an administrator explicitly opted in. */
declare(strict_types=1);
if (! defined('WP_UNINSTALL_PLUGIN')) { exit; }
// DigiForge V1 is single-site. Never perform destructive uninstall operations during multisite/network uninstall.
if (is_multisite()) { return; }
if (! get_option('digiforge_cleanup_on_uninstall', false)) { return; }
require_once __DIR__ . '/includes/Core/Capabilities.php';
\DigiForge\Core\Capabilities::remove();
global $wpdb;
foreach (['settings','audit_log','jobs','idempotency','opportunities','product_families','products','product_versions','digital_products','digital_files','digital_file_versions','digital_packages','digital_previews','digital_templates','digital_licenses','digital_download_checks'] as $table) { $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($wpdb->prefix . 'digiforge_' . $table) . '`'); }
delete_option('digiforge_db_version');
delete_option('digiforge_db_schema_version');
delete_option('digiforge_cleanup_on_uninstall');
