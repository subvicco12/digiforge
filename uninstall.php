<?php
/** Uninstall only removes business data when an administrator explicitly opted in. */
declare(strict_types=1);
if (! defined('WP_UNINSTALL_PLUGIN')) { exit; }
if (! get_option('digiforge_cleanup_on_uninstall', false)) { return; }
global $wpdb;
foreach (['settings','audit_log','jobs','idempotency'] as $table) { $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($wpdb->prefix . 'digiforge_' . $table) . '`'); }
delete_option('digiforge_db_version');
delete_option('digiforge_cleanup_on_uninstall');
