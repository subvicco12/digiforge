<?php
declare(strict_types=1);
namespace DigiForge\Queue;
use DigiForge\Database\Tables;
/** Reserves operation keys before future external side effects to prevent duplicate requests. */
final class Idempotency {
    public function reserve(string $key, string $operation_type): bool {
        global $wpdb;
        $key = sanitize_text_field($key);
        $operation_type = sanitize_key($operation_type);
        if ($key === '' || $operation_type === '') { return false; }
        return 1 === $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . Tables::idempotency() . ' (operation_key, operation_type, status, created_at, updated_at) VALUES (%s,%s,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP())', $key, $operation_type, 'PENDING'));
    }
    public function complete(string $key, string $response_hash = ''): bool {
        global $wpdb;
        $key = sanitize_text_field($key);
        if ($key === '') { return false; }
        $updated = $wpdb->update(Tables::idempotency(), ['status' => 'SUCCESS', 'response_hash' => hash('sha256', $response_hash), 'updated_at' => current_time('mysql', true)], ['operation_key' => $key], ['%s','%s','%s'], ['%s']);
        if (false === $updated) { return false; }
        if ($updated > 0) { return true; }
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Tables::idempotency() . ' WHERE operation_key = %s', $key));
    }
}
