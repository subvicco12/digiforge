<?php
declare(strict_types=1);
namespace DigiForge\Queue;
use DigiForge\Database\Tables;
/** Persists job intent only. Worker execution is deliberately absent from this foundation. */
final class JobRepository {
    public function enqueue(string $type, array $payload = [], string $idempotency_key = ''): int|false {
        global $wpdb;
        $idempotency_key = sanitize_text_field($idempotency_key);
        if ($idempotency_key !== '') {
            $found = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Tables::jobs() . ' WHERE idempotency_key = %s', $idempotency_key));
            if ($found) { return (int) $found; }
        }
        $data = ['job_type' => sanitize_key($type), 'state' => 'BLOCKED', 'payload' => wp_json_encode($payload), 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)];
        $formats = ['%s','%s','%s','%s','%s'];
        if ($idempotency_key !== '') {
            $data['idempotency_key'] = $idempotency_key;
            $formats[] = '%s';
        } else {
            $data['idempotency_key'] = null;
            $formats[] = null;
        }
        $ok = $wpdb->insert(Tables::jobs(), $data, $formats);
        return $ok ? (int) $wpdb->insert_id : false;
    }
    public function transition(int $id, string $state): bool { if (! JobState::valid($state)) { return false; } global $wpdb; return false !== $wpdb->update(Tables::jobs(), ['state' => $state, 'updated_at' => current_time('mysql', true)], ['id' => $id], ['%s','%s'], ['%d']); }
}
