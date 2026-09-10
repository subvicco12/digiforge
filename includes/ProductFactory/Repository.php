<?php
declare(strict_types=1);
namespace DigiForge\ProductFactory;

use DigiForge\Database\Tables;
use DigiForge\Security\Logger;

/** Persistence boundary for Product Factory entities. Relationships are validated before writes. */
final class Repository {
    public const DEFAULT_PAGE_SIZE = 20;
    public const MAX_PAGE_SIZE = 100;
    private const DEFINITIONS = [
        'opportunity' => ['table' => 'opportunities', 'label' => 'title', 'text' => 'description'],
        'product_family' => ['table' => 'product_families', 'label' => 'name', 'text' => 'description', 'parent' => 'opportunity_id', 'parent_type' => 'opportunity'],
        'product' => ['table' => 'products', 'label' => 'name', 'text' => 'description', 'parent' => 'product_family_id', 'parent_type' => 'product_family'],
        'product_version' => ['table' => 'product_versions', 'label' => 'version_label', 'text' => 'notes', 'parent' => 'product_id', 'parent_type' => 'product'],
    ];

    public function create(string $type, array $input, ?string $idempotency_key = null): array|\WP_Error {
        $definition = self::DEFINITIONS[$type] ?? null;
        if ($definition === null) { return $this->error('invalid_type', 'Unknown entity type.'); }
        $label = sanitize_text_field((string) ($input[$definition['label']] ?? ''));
        if ($label === '') { return $this->error('validation', sprintf('%s is required.', $definition['label'])); }
        $key = $idempotency_key === null ? null : sanitize_text_field($idempotency_key);
        if ($key === '') { $key = null; }
        if ($key !== null && strlen($key) > 191) { return $this->error('validation', 'Idempotency key is too long.'); }
        if ($key !== null && ($existing = $this->find_by_key($type, $key)) !== null) { return $existing + ['idempotent_replay' => true]; }
        $data = [$definition['label'] => $label, $definition['text'] => sanitize_textarea_field((string) ($input[$definition['text']] ?? '')), 'state' => Lifecycle::initial($type), 'idempotency_key' => $key, 'created_by' => get_current_user_id(), 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)];
        if (isset($definition['parent'])) {
            $parent_id = absint($input[$definition['parent']] ?? 0);
            if ($parent_id < 1 || $this->find($definition['parent_type'], $parent_id) === null) { return $this->error('invalid_relationship', 'A valid parent is required.'); }
            $data[$definition['parent']] = $parent_id;
        }
        global $wpdb;
        if (! $wpdb->insert($this->table($type), $data)) {
            if ($key !== null && ($existing = $this->find_by_key($type, $key)) !== null) { return $existing + ['idempotent_replay' => true]; }
            return $this->error('create_failed', 'Unable to create entity.', 500);
        }
        $entity = $this->find($type, (int) $wpdb->insert_id);
        Logger::audit($type . '_created', ['state' => $data['state'], 'idempotency_key' => $key === null ? '' : '[PRESENT]'], $type, (string) $wpdb->insert_id);
        return $entity ?? $this->error('create_failed', 'Unable to read created entity.', 500);
    }

    public function find(string $type, int $id): ?array {
        if (! isset(self::DEFINITIONS[$type]) || $id < 1) { return null; }
        global $wpdb; $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table($type) . ' WHERE id = %d', $id), ARRAY_A);
        return is_array($row) ? $this->normalize($row) : null;
    }
    public function all(string $type, int $page = 1, int $per_page = self::DEFAULT_PAGE_SIZE): array {
        if (! isset(self::DEFINITIONS[$type])) { return ['items' => [], 'pagination' => ['page' => 1, 'per_page' => self::DEFAULT_PAGE_SIZE, 'total_items' => 0, 'total_pages' => 0]]; }
        $page = max(1, $page);
        $per_page = min(self::MAX_PAGE_SIZE, max(1, $per_page));
        $offset = ($page - 1) * $per_page;
        global $wpdb;
        $table = $this->table($type);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $table . ' ORDER BY id DESC LIMIT %d OFFSET %d', $per_page, $offset), ARRAY_A);
        $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table);
        return [
            'items' => array_map([$this, 'normalize'], is_array($rows) ? $rows : []),
            'pagination' => ['page' => $page, 'per_page' => $per_page, 'total_items' => $total, 'total_pages' => (int) ceil($total / $per_page)],
        ];
    }
    public function transition(string $type, int $id, string $to): array|\WP_Error {
        $entity = $this->find($type, $id); $to = strtoupper(sanitize_key($to));
        if ($entity === null) { return $this->error('not_found', 'Entity not found.', 404); }
        $from = (string) $entity['state'];
        if (! Lifecycle::can_transition($type, $from, $to)) { return $this->error('invalid_transition', "Cannot transition $type from $from to $to.", 409); }
        global $wpdb;
        $updated = $wpdb->update($this->table($type), ['state' => $to, 'updated_at' => current_time('mysql', true)], ['id' => $id, 'state' => $from], ['%s', '%s'], ['%d', '%s']);
        if ($updated !== 1) { return $this->error('transition_conflict', 'Entity changed concurrently.', 409); }
        Logger::audit($type . '_state_changed', ['from' => $from, 'to' => $to], $type, (string) $id);
        return $this->find($type, $id) ?? $this->error('not_found', 'Entity not found.', 404);
    }
    private function find_by_key(string $type, string $key): ?array { global $wpdb; $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table($type) . ' WHERE idempotency_key = %s', $key), ARRAY_A); return is_array($row) ? $this->normalize($row) : null; }
    private function table(string $type): string { $method = self::DEFINITIONS[$type]['table']; return Tables::$method(); }
    private function normalize(array $row): array { foreach (['id', 'opportunity_id', 'product_family_id', 'product_id', 'created_by'] as $key) { if (isset($row[$key])) { $row[$key] = (int) $row[$key]; } } unset($row['idempotency_key']); return $row; }
    private function error(string $code, string $message, int $status = 400): \WP_Error { return new \WP_Error('digiforge_' . $code, __($message, 'digiforge'), ['status' => $status]); }
}
