<?php
declare(strict_types=1);
namespace DigiForge\ProductFactory;

use DigiForge\Database\Tables;
use DigiForge\Queue\JobRepository;
use DigiForge\Security\Logger;

/** WordPress/MySQL repository for the Opportunity → Family → Product → Version hierarchy. */
final class Repository {
    private const TABLES = ['opportunity' => 'opportunities', 'product_family' => 'product_families', 'product' => 'products', 'product_version' => 'product_versions'];
    private const DEFAULTS = ['opportunity' => 'NEW', 'product_family' => 'DRAFT', 'product' => 'DRAFT', 'product_version' => 'DRAFT'];

    public function create(string $type, array $input): int|\WP_Error {
        if (! isset(self::TABLES[$type])) { return new \WP_Error('digiforge_invalid_entity', __('Unknown Product Factory entity.', 'digiforge'), ['status' => 400]); }
        $data = $this->sanitize($type, $input);
        if (is_wp_error($data)) { return $data; }
        global $wpdb;
        $data['status'] = self::DEFAULTS[$type];
        $data['created_by'] = get_current_user_id();
        $data['created_at'] = current_time('mysql', true);
        $data['updated_at'] = current_time('mysql', true);
        if ($type === 'product_version') { $data['version_number'] = $this->next_version((int) $data['product_id']); }
        if (! $wpdb->insert($this->table($type), $data)) { return new \WP_Error('digiforge_create_failed', __('Unable to create entity.', 'digiforge'), ['status' => 500]); }
        $id = (int) $wpdb->insert_id;
        Logger::audit($type . '_created', ['status' => $data['status'], 'parent_id' => $this->parent_id($type, $data)], $type, (string) $id);
        return $id;
    }
    /** Validate request data before an idempotency reservation is made. */
    public function validate(string $type, array $input): true|\WP_Error { $data = $this->sanitize($type, $input); return is_wp_error($data) ? $data : true; }

    public function find(string $type, int $id): ?array {
        if (! isset(self::TABLES[$type]) || $id < 1) { return null; }
        global $wpdb; $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table($type) . ' WHERE id = %d', $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }
    public function list(string $type, int $parent_id = 0): array {
        if (! isset(self::TABLES[$type])) { return []; }
        global $wpdb; $parent = $this->parent_column($type);
        $sql = 'SELECT * FROM ' . $this->table($type);
        if ($parent !== '' && $parent_id > 0) { $sql .= $wpdb->prepare(' WHERE ' . $parent . ' = %d', $parent_id); }
        $sql .= ' ORDER BY id DESC';
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }
    public function transition(string $type, int $id, string $to): true|\WP_Error {
        $entity = $this->find($type, $id);
        $to = strtoupper(sanitize_key($to));
        if ($entity === null) { return new \WP_Error('digiforge_not_found', __('Entity was not found.', 'digiforge'), ['status' => 404]); }
        if (! Lifecycle::valid($type, $to) || ! Lifecycle::can_transition($type, (string) $entity['status'], $to)) {
            return new \WP_Error('digiforge_invalid_transition', __('Invalid lifecycle transition.', 'digiforge'), ['status' => 409]);
        }
        global $wpdb;
        if (false === $wpdb->update($this->table($type), ['status' => $to, 'updated_at' => current_time('mysql', true)], ['id' => $id], ['%s', '%s'], ['%d'])) {
            return new \WP_Error('digiforge_transition_failed', __('Unable to update lifecycle state.', 'digiforge'), ['status' => 500]);
        }
        Logger::audit($type . '_state_changed', ['from' => $entity['status'], 'to' => $to], $type, (string) $id);
        // A released version is a boundary for future work, not permission to automate it.
        if ($type === 'product_version' && $to === 'RELEASED') { (new JobRepository())->enqueue('product_version_released', ['product_version_id' => $id], 'product-version-release-' . $id); }
        return true;
    }
    private function sanitize(string $type, array $input): array|\WP_Error {
        $title = sanitize_text_field((string) ($input[$type === 'opportunity' ? 'title' : 'name'] ?? ''));
        if ($title === '') { return new \WP_Error('digiforge_validation_failed', __('A name is required.', 'digiforge'), ['status' => 400]); }
        $data = $type === 'opportunity' ? ['title' => $title, 'description' => sanitize_textarea_field((string) ($input['description'] ?? '')), 'source' => sanitize_text_field((string) ($input['source'] ?? ''))] : ['name' => $title, 'description' => sanitize_textarea_field((string) ($input['description'] ?? ''))];
        $parent = $this->parent_column($type);
        if ($parent !== '') { $parent_id = absint($input[$parent] ?? 0); if ($parent_id < 1 || ! $this->parent_exists($type, $parent_id)) { return new \WP_Error('digiforge_invalid_relationship', __('A valid parent entity is required.', 'digiforge'), ['status' => 400]); } $data[$parent] = $parent_id; }
        if ($type === 'product') { $sku = sanitize_text_field((string) ($input['sku'] ?? '')); $data['sku'] = $sku === '' ? null : $sku; }
        if ($type === 'product_version') { $data['metadata'] = wp_json_encode($this->sanitize_metadata(is_array($input['metadata'] ?? null) ? $input['metadata'] : [])); }
        return $data;
    }
    private function parent_exists(string $type, int $id): bool { $parent_type = ['product_family' => 'opportunity', 'product' => 'product_family', 'product_version' => 'product'][$type] ?? ''; return $parent_type !== '' && $this->find($parent_type, $id) !== null; }
    private function parent_column(string $type): string { return ['product_family' => 'opportunity_id', 'product' => 'product_family_id', 'product_version' => 'product_id'][$type] ?? ''; }
    private function parent_id(string $type, array $data): int { $column = $this->parent_column($type); return $column === '' ? 0 : (int) $data[$column]; }
    private function table(string $type): string { $method = self::TABLES[$type]; return Tables::$method(); }
    private function next_version(int $product_id): int { global $wpdb; return 1 + (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(version_number) FROM ' . Tables::product_versions() . ' WHERE product_id = %d', $product_id)); }
    private function sanitize_metadata(array $metadata): array { $clean = []; foreach ($metadata as $key => $value) { $key = sanitize_key((string) $key); if ($key === '') { continue; } $clean[$key] = is_array($value) ? $this->sanitize_metadata($value) : (is_bool($value) || is_int($value) || is_float($value) ? $value : sanitize_text_field((string) $value)); } return $clean; }
}
