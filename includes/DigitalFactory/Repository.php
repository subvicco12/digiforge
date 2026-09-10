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
        'digital_product' => ['table' => 'digital_products', 'required' => ['name', 'category', 'product_id', 'product_version_id']],
        'digital_file' => ['table' => 'digital_files', 'required' => ['name', 'file_type', 'digital_product_id', 'product_version_id']],
        'digital_file_version' => ['table' => 'digital_file_versions', 'required' => ['version_label', 'digital_file_id']],
        'digital_package' => ['table' => 'digital_packages', 'required' => ['name', 'digital_product_id', 'product_version_id']],
        'digital_preview' => ['table' => 'digital_previews', 'required' => ['name', 'digital_product_id', 'digital_file_id']],
        'digital_template' => ['table' => 'digital_templates', 'required' => ['name', 'digital_product_id', 'digital_file_id', 'digital_preview_id']],
        'digital_license' => ['table' => 'digital_licenses', 'required' => ['name', 'license_type', 'digital_product_id']],
        'digital_download_check' => ['table' => 'digital_download_checks', 'required' => ['digital_product_id', 'target_type', 'target_id', 'check_type']],
    ];
    private const IDS = ['product_id', 'product_version_id', 'digital_product_id', 'digital_file_id', 'digital_preview_id', 'target_id', 'byte_size'];
    private const TEXT = ['name', 'category', 'file_type', 'mime_type', 'storage_reference', 'version_label', 'generation_status', 'preview_reference', 'template_reference', 'license_type', 'target_type', 'failure_reason'];
    private const LONGTEXT = ['manifest', 'access_instructions', 'terms', 'details'];

    public function create(string $type, array $input, ?string $idempotency_key = null): array|\WP_Error {
        $definition = self::DEFINITIONS[$type] ?? null;
        if ($definition === null) { return $this->error('invalid_type', 'Unknown digital entity type.'); }
        $key = $this->key($idempotency_key);
        if (is_wp_error($key)) { return $key; }
        if ($key !== null && ($existing = $this->find_by_key($type, $key)) !== null) { return $existing + ['idempotent_replay' => true]; }
        $data = $this->sanitize($input);
        foreach ($definition['required'] as $field) { if (! isset($data[$field]) || $data[$field] === '' || $data[$field] === 0) { return $this->error('validation', "$field is required."); } }
        $relationship = $this->validate_relationships($type, $data);
        if (is_wp_error($relationship)) { return $relationship; }
        if ($type === 'digital_product') { $data['state'] = 'DRAFT'; $data['readiness'] = wp_json_encode(Lifecycle::readiness()); }
        if ($type === 'digital_download_check') {
            $data['check_type'] = Validator::check((string) $data['check_type']);
            $data['validation_result'] = Validator::result((string) ($input['validation_result'] ?? 'PENDING'));
            $data['review_status'] = Validator::review((string) ($input['review_status'] ?? 'UNREVIEWED'));
            if ($data['check_type'] === '' || $data['validation_result'] === '' || $data['review_status'] === '') { return $this->error('validation', 'Invalid QA check, result, or review status.'); }
        }
        foreach (['checksum_sha256'] as $field) { if (isset($input[$field])) { $checksum = Validator::checksum((string) $input[$field]); if ($checksum === '' && $input[$field] !== '') { return $this->error('validation', 'Checksum must be SHA-256.'); } $data[$field] = $checksum; } }
        if ($type === 'digital_license' && isset($input['license_code'])) { $data['license_code_hash'] = hash('sha256', (string) $input['license_code']); }
        $now = current_time('mysql', true); $data += ['idempotency_key' => $key, 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now];
        global $wpdb;
        if (! $wpdb->insert($this->table($type), $data)) { if ($key !== null && ($existing = $this->find_by_key($type, $key)) !== null) { return $existing + ['idempotent_replay' => true]; } return $this->error('create_failed', 'Unable to create digital entity.', 500); }
        Logger::audit($type . '_created', ['idempotency_key' => $key === null ? '' : '[PRESENT]'], $type, (string) $wpdb->insert_id);
        return $this->find($type, (int) $wpdb->insert_id) ?? $this->error('create_failed', 'Unable to read created entity.', 500);
    }

    public function update(string $type, int $id, array $input): array|\WP_Error {
        $current = $this->find($type, $id); if ($current === null) { return $this->error('not_found', 'Digital entity not found.', 404); }
        $data = $this->sanitize($input); unset($data['id'], $data['state'], $data['readiness'], $data['created_by'], $data['created_at'], $data['idempotency_key']);
        if ($type === 'digital_download_check') { foreach (['validation_result' => 'result', 'review_status' => 'review', 'check_type' => 'check'] as $field => $method) { if (isset($input[$field])) { $data[$field] = Validator::$method((string) $input[$field]); if ($data[$field] === '') { return $this->error('validation', "Invalid $field."); } } } }
        if (isset($input['checksum_sha256'])) { $data['checksum_sha256'] = Validator::checksum((string) $input['checksum_sha256']); if ($data['checksum_sha256'] === '' && $input['checksum_sha256'] !== '') { return $this->error('validation', 'Checksum must be SHA-256.'); } }
        if ($data === []) { return $this->error('validation', 'No writable fields supplied.'); }
        $relationship = $this->validate_relationships($type, $data + $current); if (is_wp_error($relationship)) { return $relationship; }
        $data['updated_at'] = current_time('mysql', true); global $wpdb;
        if ($wpdb->update($this->table($type), $data, ['id' => $id]) === false) { return $this->error('update_failed', 'Unable to update digital entity.', 500); }
        Logger::audit($type . '_updated', ['fields' => array_keys($data)], $type, (string) $id);
        return $this->find($type, $id) ?? $this->error('not_found', 'Digital entity not found.', 404);
    }

    public function transition(int $id, string $to): array|\WP_Error {
        $entity = $this->find('digital_product', $id); $to = strtoupper(sanitize_key($to));
        if ($entity === null) { return $this->error('not_found', 'Digital product not found.', 404); }
        $from = (string) $entity['state']; if (! Lifecycle::can_transition($from, $to)) { return $this->error('invalid_transition', "Cannot transition digital product from $from to $to.", 409); }
        global $wpdb; $updated = $wpdb->update(Tables::digital_products(), ['state' => $to, 'updated_at' => current_time('mysql', true)], ['id' => $id, 'state' => $from], ['%s', '%s'], ['%d', '%s']);
        if ($updated !== 1) { return $this->error('transition_conflict', 'Digital product changed concurrently.', 409); }
        Logger::audit('digital_product_state_changed', ['from' => $from, 'to' => $to], 'digital_product', (string) $id);
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
    private function sanitize(array $input): array {
        $data = [];
        foreach (self::IDS as $field) { if (array_key_exists($field, $input)) { $data[$field] = absint($input[$field]); } }
        foreach (self::TEXT as $field) { if (array_key_exists($field, $input)) { $data[$field] = sanitize_text_field((string) $input[$field]); } }
        foreach (self::LONGTEXT as $field) { if (array_key_exists($field, $input)) { $data[$field] = sanitize_textarea_field((string) $input[$field]); } }
        foreach (['checksum_sha256', 'status', 'validation_result', 'review_status', 'check_type'] as $field) { if (array_key_exists($field, $input)) { $data[$field] = sanitize_text_field((string) $input[$field]); } }
        return $data;
    }
    private function validate_relationships(string $type, array $data): true|\WP_Error {
        $product = fn(int $id): ?array => (new \DigiForge\ProductFactory\Repository())->find('product', $id);
        $version = fn(int $id): ?array => (new \DigiForge\ProductFactory\Repository())->find('product_version', $id);
        if ($type === 'digital_product') { $p = $product($data['product_id']); $v = $version($data['product_version_id']); if ($p === null || $v === null || (int) $v['product_id'] !== (int) $data['product_id']) { return $this->error('invalid_relationship', 'Product version must belong to the product.'); } }
        if (isset($data['digital_product_id'])) { $dp = $this->find('digital_product', (int) $data['digital_product_id']); if ($dp === null) { return $this->error('invalid_relationship', 'Digital product does not exist.'); } if (isset($data['product_version_id']) && (int) $dp['product_version_id'] !== (int) $data['product_version_id']) { return $this->error('invalid_relationship', 'Product version does not match the digital product.'); } }
        if (isset($data['digital_file_id'])) { $file = $this->find('digital_file', (int) $data['digital_file_id']); if ($file === null || (isset($data['digital_product_id']) && (int) $file['digital_product_id'] !== (int) $data['digital_product_id'])) { return $this->error('invalid_relationship', 'File must belong to the digital product.'); } }
        if (isset($data['digital_preview_id'])) { $preview = $this->find('digital_preview', (int) $data['digital_preview_id']); if ($preview === null || (int) $preview['digital_product_id'] !== (int) $data['digital_product_id'] || (int) $preview['digital_file_id'] !== (int) $data['digital_file_id']) { return $this->error('invalid_relationship', 'Preview must match the product and file.'); } }
        if ($type === 'digital_download_check') { $targets = ['file' => 'digital_file', 'file_version' => 'digital_file_version', 'package' => 'digital_package', 'preview' => 'digital_preview', 'template' => 'digital_template', 'license' => 'digital_license']; $target_type = $targets[$data['target_type']] ?? null; $target = $target_type === null ? null : $this->find($target_type, (int) $data['target_id']); if ($target === null || (isset($target['digital_product_id']) && (int) $target['digital_product_id'] !== (int) $data['digital_product_id'])) { return $this->error('invalid_relationship', 'QA target must belong to the digital product.'); } }
        return true;
    }
    private function key(?string $key): string|null|\WP_Error { if ($key === null || trim($key) === '') { return null; } $key = sanitize_text_field($key); return strlen($key) > 191 ? $this->error('validation', 'Idempotency key is too long.') : $key; }
    private function find_by_key(string $type, string $key): ?array { global $wpdb; $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table($type) . ' WHERE idempotency_key = %s', $key), ARRAY_A); return is_array($row) ? $this->normalize($row) : null; }
    private function table(string $type): string { $method = self::DEFINITIONS[$type]['table']; return Tables::$method(); }
    private function normalize(array $row): array { foreach (array_merge(['id', 'created_by'], self::IDS) as $field) { if (isset($row[$field])) { $row[$field] = (int) $row[$field]; } } unset($row['idempotency_key'], $row['license_code_hash']); foreach (['readiness', 'manifest', 'details'] as $field) { if (isset($row[$field]) && is_string($row[$field])) { $decoded = json_decode($row[$field], true); if (is_array($decoded)) { $row[$field] = $decoded; } } } return $row; }
    private function error(string $code, string $message, int $status = 400): \WP_Error { return new \WP_Error('digiforge_' . $code, __($message, 'digiforge'), ['status' => $status]); }
}
