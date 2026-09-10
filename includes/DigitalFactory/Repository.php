<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;

use DigiForge\Database\Tables;
use DigiForge\Security\Logger;

/** Persistence boundary for the internal-only Digital Product Factory. */
final class Repository {
    public const DEFAULT_PAGE_SIZE = 20;
    public const MAX_PAGE_SIZE = 100;
    private const DEFINITIONS = [
        'digital_product' => [
            'table' => 'digital_products', 'required' => ['name', 'category', 'product_id', 'product_version_id'],
            'create' => ['name', 'category', 'product_id', 'product_version_id'], 'update' => ['name', 'category', 'product_id', 'product_version_id'],
        ],
        'digital_file' => [
            'table' => 'digital_files', 'required' => ['name', 'file_type', 'digital_product_id', 'product_version_id'],
            'create' => ['name', 'file_type', 'mime_type', 'storage_reference', 'checksum_sha256', 'status', 'digital_product_id', 'product_version_id'],
            'update' => ['name', 'file_type', 'mime_type', 'storage_reference', 'checksum_sha256', 'status', 'digital_product_id', 'product_version_id'],
        ],
        'digital_file_version' => [
            'table' => 'digital_file_versions', 'required' => ['version_label', 'digital_file_id'],
            'create' => ['version_label', 'digital_file_id', 'storage_reference', 'checksum_sha256', 'byte_size', 'status'],
            'update' => ['digital_file_id', 'storage_reference', 'checksum_sha256', 'byte_size', 'status'],
        ],
        'digital_package' => [
            'table' => 'digital_packages', 'required' => ['name', 'digital_product_id', 'product_version_id'],
            'create' => ['name', 'digital_product_id', 'product_version_id', 'manifest', 'checksum_sha256', 'byte_size', 'generation_status'],
            'update' => ['name', 'digital_product_id', 'product_version_id', 'manifest', 'checksum_sha256', 'byte_size', 'generation_status'],
        ],
        'digital_preview' => [
            'table' => 'digital_previews', 'required' => ['name', 'digital_product_id', 'digital_file_id'],
            'create' => ['name', 'digital_product_id', 'digital_file_id', 'preview_reference', 'status'],
            'update' => ['name', 'digital_product_id', 'digital_file_id', 'preview_reference', 'status'],
        ],
        'digital_template' => [
            'table' => 'digital_templates', 'required' => ['name', 'digital_product_id', 'digital_file_id', 'digital_preview_id'],
            'create' => ['name', 'digital_product_id', 'digital_file_id', 'digital_preview_id', 'template_reference', 'access_instructions', 'status'],
            'update' => ['name', 'digital_product_id', 'digital_file_id', 'digital_preview_id', 'template_reference', 'access_instructions', 'status'],
        ],
        'digital_license' => [
            'table' => 'digital_licenses', 'required' => ['name', 'license_type', 'digital_product_id'],
            'create' => ['name', 'license_type', 'digital_product_id', 'terms', 'license_code', 'status'],
            'update' => ['name', 'license_type', 'digital_product_id', 'terms', 'license_code', 'status'],
        ],
        'digital_download_check' => [
            'table' => 'digital_download_checks', 'required' => ['digital_product_id', 'target_type', 'target_id', 'check_type'],
            'create' => ['digital_product_id', 'target_type', 'target_id', 'check_type', 'validation_result', 'failure_reason', 'review_status', 'details'],
            'update' => ['digital_product_id', 'target_type', 'target_id', 'check_type', 'validation_result', 'failure_reason', 'review_status', 'details'],
        ],
    ];
    private const IDS = ['product_id', 'product_version_id', 'digital_product_id', 'digital_file_id', 'digital_preview_id', 'target_id', 'byte_size'];
    private const TEXT = ['name', 'category', 'file_type', 'mime_type', 'storage_reference', 'version_label', 'generation_status', 'preview_reference', 'template_reference', 'license_type', 'target_type', 'failure_reason', 'status'];
    private const TEXTAREA = ['access_instructions', 'terms'];
    private const STRUCTURED = ['manifest', 'details'];

    public function create(string $type, array $input, ?string $idempotency_key = null): array|\WP_Error {
        $definition = self::DEFINITIONS[$type] ?? null;
        if ($definition === null) { return $this->error('invalid_type', 'Unknown digital entity type.'); }
        unset($input['idempotency_key']);
        $fields = $this->validate_fields($input, $definition['create']);
        if (is_wp_error($fields)) { return $fields; }
        $key = $this->key($idempotency_key);
        if (is_wp_error($key)) { return $key; }
        if ($key !== null && ($existing = $this->find_by_key($type, $key)) !== null) { return $existing + ['idempotent_replay' => true]; }
        $data = $this->sanitize($input);
        if (is_wp_error($data)) { return $data; }
        $required = $this->validate_required($definition, $data);
        if (is_wp_error($required)) { return $required; }
        $relationship = $this->validate_relationships($type, $data);
        if (is_wp_error($relationship)) { return $relationship; }
        if ($type === 'digital_product') { $data['state'] = 'DRAFT'; $data['readiness'] = wp_json_encode(Lifecycle::readiness()); }
        $validated = $this->validate_special_fields($type, $data, $input);
        if (is_wp_error($validated)) { return $validated; }
        $data = $validated;
        $now = current_time('mysql', true); $data += ['idempotency_key' => $key, 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now];
        global $wpdb;
        if (! $wpdb->insert($this->table($type), $data)) { if ($key !== null && ($existing = $this->find_by_key($type, $key)) !== null) { return $existing + ['idempotent_replay' => true]; } return $this->error('create_failed', 'Unable to create digital entity.', 500); }
        Logger::audit($type . '_created', ['idempotency_key' => $key === null ? '' : '[PRESENT]'], $type, (string) $wpdb->insert_id);
        return $this->find($type, (int) $wpdb->insert_id) ?? $this->error('create_failed', 'Unable to read created entity.', 500);
    }

    public function update(string $type, int $id, array $input): array|\WP_Error {
        $definition = self::DEFINITIONS[$type] ?? null;
        if ($definition === null) { return $this->error('invalid_type', 'Unknown digital entity type.'); }
        $current = $this->find($type, $id); if ($current === null) { return $this->error('not_found', 'Digital entity not found.', 404); }
        $fields = $this->validate_fields($input, $definition['update']);
        if (is_wp_error($fields)) { return $fields; }
        $data = $this->sanitize($input);
        if (is_wp_error($data)) { return $data; }
        if ($data === []) { return $this->error('validation', 'No writable fields supplied.'); }
        $validated = $this->validate_special_fields($type, $data, $input);
        if (is_wp_error($validated)) { return $validated; }
        $data = $validated;
        $prospective = $data + $current;
        $required = $this->validate_required($definition, $prospective);
        if (is_wp_error($required)) { return $required; }
        $relationship = $this->validate_relationships($type, $prospective); if (is_wp_error($relationship)) { return $relationship; }
        $descendants = $this->validate_descendants($type, $id, $prospective); if (is_wp_error($descendants)) { return $descendants; }
        $data['updated_at'] = current_time('mysql', true); global $wpdb;
        if ($wpdb->update($this->table($type), $data, ['id' => $id]) === false) { return $this->error('update_failed', 'Unable to update digital entity.', 500); }
        Logger::audit($type . '_updated', ['fields' => array_keys($data)], $type, (string) $id);
        return $this->find($type, $id) ?? $this->error('not_found', 'Digital entity not found.', 404);
    }

    public function transition(int $id, string $to): array|\WP_Error {
        $entity = $this->find('digital_product', $id); $to = strtoupper(sanitize_key($to));
        if ($entity === null) { return $this->error('not_found', 'Digital product not found.', 404); }
        $from = (string) $entity['state']; if (! Lifecycle::can_transition($from, $to)) { return $this->error('invalid_transition', "Cannot transition digital product from $from to $to.", 409); }
        $readiness = Lifecycle::advance_readiness(is_array($entity['readiness'] ?? null) ? $entity['readiness'] : [], $to);
        global $wpdb; $updated = $wpdb->update(Tables::digital_products(), ['state' => $to, 'readiness' => wp_json_encode($readiness), 'updated_at' => current_time('mysql', true)], ['id' => $id, 'state' => $from], ['%s', '%s', '%s'], ['%d', '%s']);
        if ($updated !== 1) { return $this->error('transition_conflict', 'Digital product changed concurrently.', 409); }
        Logger::audit('digital_product_state_changed', ['from' => $from, 'to' => $to, 'readiness' => $readiness], 'digital_product', (string) $id);
        return $this->find('digital_product', $id) ?? $this->error('not_found', 'Digital product not found.', 404);
    }

    public function find(string $type, int $id): ?array { if (! isset(self::DEFINITIONS[$type]) || $id < 1) { return null; } global $wpdb; $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table($type) . ' WHERE id = %d', $id), ARRAY_A); return is_array($row) ? $this->normalize($row) : null; }
    public function all(string $type, int $page = 1, int $per_page = self::DEFAULT_PAGE_SIZE): array {
        $page = max(1, $page); $per_page = min(self::MAX_PAGE_SIZE, max(1, $per_page));
        if (! isset(self::DEFINITIONS[$type])) { return ['items' => [], 'pagination' => compact('page', 'per_page') + ['total_items' => 0, 'total_pages' => 0]]; }
        global $wpdb; $table = $this->table($type); $offset = ($page - 1) * $per_page;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $table . ' ORDER BY id DESC LIMIT %d OFFSET %d', $per_page, $offset), ARRAY_A); $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table);
        return ['items' => array_map([$this, 'normalize'], is_array($rows) ? $rows : []), 'pagination' => ['page' => $page, 'per_page' => $per_page, 'total_items' => $total, 'total_pages' => (int) ceil($total / $per_page)]];
    }

    private function validate_fields(array $input, array $allowed): true|\WP_Error {
        $unknown = array_diff(array_keys($input), $allowed);
        return $unknown === [] ? true : $this->error('invalid_field', 'Unknown or immutable field: ' . implode(', ', array_map(static fn(mixed $field): string => sanitize_key((string) $field), $unknown)) . '.');
    }
    private function validate_required(array $definition, array $data): true|\WP_Error {
        foreach ($definition['required'] as $field) {
            if (! array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '' || $data[$field] === 0) {
                return $this->error('validation', "$field is required.");
            }
        }
        return true;
    }
    private function sanitize(array $input): array|\WP_Error {
        $data = [];
        foreach ($input as $field => $value) {
            if (in_array($field, self::IDS, true)) { if (! is_scalar($value)) { return $this->error('validation', "$field must be numeric."); } $data[$field] = absint($value); }
            elseif (in_array($field, self::TEXT, true)) { if (! is_scalar($value)) { return $this->error('validation', "$field must be text."); } $data[$field] = sanitize_text_field((string) $value); }
            elseif (in_array($field, self::TEXTAREA, true)) { if (! is_scalar($value)) { return $this->error('validation', "$field must be text."); } $data[$field] = sanitize_textarea_field((string) $value); }
            elseif (in_array($field, self::STRUCTURED, true)) { $encoded = $this->structured_json($value); if (is_wp_error($encoded)) { return $encoded; } $data[$field] = $encoded; }
            elseif ($field === 'checksum_sha256' || in_array($field, ['validation_result', 'review_status', 'check_type'], true)) { if (! is_scalar($value)) { return $this->error('validation', "$field must be text."); } $data[$field] = sanitize_text_field((string) $value); }
            elseif ($field === 'license_code') { if (! is_scalar($value)) { return $this->error('validation', 'license_code must be text.'); } $data['license_code_hash'] = hash('sha256', (string) $value); }
        }
        return $data;
    }
    private function structured_json(mixed $value): string|\WP_Error {
        if (is_string($value)) { $value = json_decode($value, true); if (! is_array($value) || json_last_error() !== JSON_ERROR_NONE) { return $this->error('validation', 'Structured fields must contain a JSON object or array.'); } }
        if (is_object($value)) { $value = get_object_vars($value); }
        if (! is_array($value)) { return $this->error('validation', 'Structured fields must contain a JSON object or array.'); }
        $sanitize = function (mixed $item) use (&$sanitize): mixed {
            if (is_object($item)) { $item = get_object_vars($item); }
            if (is_array($item)) {
                $clean = [];
                foreach ($item as $key => $child) {
                    $clean[$key] = $sanitize($child);
                }
                return $clean;
            }
            if (is_string($item)) { return sanitize_text_field($item); }
            return is_int($item) || is_float($item) || is_bool($item) || $item === null ? $item : sanitize_text_field((string) $item);
        };
        $encoded = wp_json_encode($sanitize($value));
        return is_string($encoded) ? $encoded : $this->error('validation', 'Structured field could not be encoded.');
    }
    private function validate_special_fields(string $type, array $data, array $input): array|\WP_Error {
        if (isset($input['checksum_sha256'])) { $checksum = Validator::checksum((string) $input['checksum_sha256']); if ($checksum === '' && $input['checksum_sha256'] !== '') { return $this->error('validation', 'Checksum must be SHA-256.'); } $data['checksum_sha256'] = $checksum; }
        if ($type === 'digital_download_check') {
            foreach (['check_type' => 'check', 'validation_result' => 'result', 'review_status' => 'review'] as $field => $method) {
                if (isset($input[$field])) { $data[$field] = Validator::$method((string) $input[$field]); if ($data[$field] === '') { return $this->error('validation', "Invalid $field."); } }
            }
            if (! isset($data['validation_result'])) { $data['validation_result'] = 'PENDING'; }
            if (! isset($data['review_status'])) { $data['review_status'] = 'UNREVIEWED'; }
        }
        return $data;
    }

    private function validate_relationships(string $type, array $data): true|\WP_Error {
        $product = fn(int $id): ?array => (new \DigiForge\ProductFactory\Repository())->find('product', $id);
        $version = fn(int $id): ?array => (new \DigiForge\ProductFactory\Repository())->find('product_version', $id);
        if ($type === 'digital_product') { $p = $product((int) $data['product_id']); $v = $version((int) $data['product_version_id']); if ($p === null || $v === null || (int) $v['product_id'] !== (int) $data['product_id']) { return $this->error('invalid_relationship', 'Product version must belong to the product.'); } }
        if (isset($data['digital_product_id'])) { $dp = $this->find('digital_product', (int) $data['digital_product_id']); if ($dp === null) { return $this->error('invalid_relationship', 'Digital product does not exist.'); } if (isset($data['product_version_id']) && (int) $dp['product_version_id'] !== (int) $data['product_version_id']) { return $this->error('invalid_relationship', 'Product version does not match the digital product.'); } }
        if (isset($data['digital_file_id'])) { $file = $this->find('digital_file', (int) $data['digital_file_id']); if ($file === null || (isset($data['digital_product_id']) && (int) $file['digital_product_id'] !== (int) $data['digital_product_id'])) { return $this->error('invalid_relationship', 'File must belong to the digital product.'); } }
        if (isset($data['digital_preview_id'])) { $preview = $this->find('digital_preview', (int) $data['digital_preview_id']); if ($preview === null || (int) $preview['digital_product_id'] !== (int) $data['digital_product_id'] || (int) $preview['digital_file_id'] !== (int) $data['digital_file_id']) { return $this->error('invalid_relationship', 'Preview must match the product and file.'); } }
        if ($type === 'digital_download_check') {
            $targets = ['file' => 'digital_file', 'file_version' => 'digital_file_version', 'package' => 'digital_package', 'preview' => 'digital_preview', 'template' => 'digital_template', 'license' => 'digital_license'];
            $target_type = $targets[$data['target_type']] ?? null; $target = $target_type === null ? null : $this->find($target_type, (int) $data['target_id']);
            if ($target_type === 'digital_file_version' && $target !== null) { $target = $this->find('digital_file', (int) $target['digital_file_id']); }
            if ($target === null || ! isset($target['digital_product_id']) || (int) $target['digital_product_id'] !== (int) $data['digital_product_id']) { return $this->error('invalid_relationship', 'QA target must belong to the digital product.'); }
        }
        return true;
    }
    private function validate_descendants(string $type, int $id, array $data): true|\WP_Error {
        global $wpdb; $mismatch = false;
        if ($type === 'digital_product') {
            $mismatch = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Tables::digital_files() . ' WHERE digital_product_id = %d AND product_version_id <> %d', $id, $data['product_version_id'])) > 0
                || (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Tables::digital_packages() . ' WHERE digital_product_id = %d AND product_version_id <> %d', $id, $data['product_version_id'])) > 0;
        } elseif ($type === 'digital_file') {
            $mismatch = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Tables::digital_previews() . ' WHERE digital_file_id = %d AND digital_product_id <> %d', $id, $data['digital_product_id'])) > 0
                || (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Tables::digital_templates() . ' WHERE digital_file_id = %d AND digital_product_id <> %d', $id, $data['digital_product_id'])) > 0
                || (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Tables::digital_download_checks() . ' dc INNER JOIN ' . Tables::digital_file_versions() . " fv ON fv.id = dc.target_id WHERE dc.target_type = 'file_version' AND fv.digital_file_id = %d AND dc.digital_product_id <> %d", $id, $data['digital_product_id'])) > 0;
        } elseif ($type === 'digital_preview') {
            $mismatch = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Tables::digital_templates() . ' WHERE digital_preview_id = %d AND (digital_product_id <> %d OR digital_file_id <> %d)', $id, $data['digital_product_id'], $data['digital_file_id'])) > 0;
        }
        $target_names = ['digital_file' => 'file', 'digital_file_version' => 'file_version', 'digital_package' => 'package', 'digital_preview' => 'preview', 'digital_template' => 'template', 'digital_license' => 'license'];
        if (isset($target_names[$type])) {
            $owner = $type === 'digital_file_version' ? $this->find('digital_file', (int) $data['digital_file_id']) : $data;
            if ($owner === null || ! isset($owner['digital_product_id'])) { return $this->error('invalid_relationship', 'Unable to resolve descendant ownership.'); }
            $mismatch = $mismatch || (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Tables::digital_download_checks() . ' WHERE target_type = %s AND target_id = %d AND digital_product_id <> %d', $target_names[$type], $id, $owner['digital_product_id'])) > 0;
        }
        return $mismatch ? $this->error('invalid_relationship', 'Parent reassignment would invalidate existing descendants.', 409) : true;
    }

    private function key(?string $key): string|null|\WP_Error { if ($key === null || trim($key) === '') { return null; } $key = sanitize_text_field($key); return strlen($key) > 191 ? $this->error('validation', 'Idempotency key is too long.') : $key; }
    private function find_by_key(string $type, string $key): ?array { global $wpdb; $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table($type) . ' WHERE idempotency_key = %s', $key), ARRAY_A); return is_array($row) ? $this->normalize($row) : null; }
    private function table(string $type): string { $method = self::DEFINITIONS[$type]['table']; return Tables::$method(); }
    private function normalize(array $row): array { foreach (array_merge(['id', 'created_by'], self::IDS) as $field) { if (isset($row[$field])) { $row[$field] = (int) $row[$field]; } } unset($row['idempotency_key'], $row['license_code_hash']); foreach (array_merge(['readiness'], self::STRUCTURED) as $field) { if (isset($row[$field]) && is_string($row[$field])) { $decoded = json_decode($row[$field], true); if (is_array($decoded)) { $row[$field] = $decoded; } } } return $row; }
    private function error(string $code, string $message, int $status = 400): \WP_Error { return new \WP_Error('digiforge_' . $code, __($message, 'digiforge'), ['status' => $status]); }
}
