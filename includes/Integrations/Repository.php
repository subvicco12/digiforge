<?php
declare(strict_types=1);
namespace DigiForge\Integrations;

use DigiForge\Database\Tables;
use DigiForge\Security\Logger;

/** Local-only integration registry. No network requests are performed by this class. */
final class Repository {
    public const PROVIDERS = ['etsy','printify','gelato','ai'];
    public const STATUSES = ['DISCONNECTED','CONFIGURED','PAUSED','ERROR'];

    public function all(int $page = 1, int $per_page = 50): array {
        global $wpdb;
        $page = max(1, $page); $per_page = min(100, max(1, $per_page)); $offset = ($page - 1) * $per_page;
        $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Tables::integrations());
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Tables::integrations() . ' ORDER BY provider, display_name LIMIT %d OFFSET %d', $per_page, $offset), ARRAY_A) ?: [];
        return ['items' => array_map([$this, 'public_row'], $rows), 'pagination' => ['page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => max(1, (int) ceil($total / $per_page))]];
    }

    public function find(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Tables::integrations() . ' WHERE id = %d', $id), ARRAY_A);
        return is_array($row) ? $this->public_row($row) : null;
    }

    public function create(array $input): array|\WP_Error {
        global $wpdb;
        $data = $this->sanitize_connection($input, true); if (is_wp_error($data)) { return $data; }
        $now = current_time('mysql', true);
        $insert = $data + ['created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now];
        $ok = $wpdb->insert(Tables::integrations(), $insert);
        if ($ok === false) { return new \WP_Error('integration_create_failed', __('Could not create integration.', 'digiforge'), ['status' => 409]); }
        $id = (int) $wpdb->insert_id; Logger::audit('integration_created', ['provider' => $data['provider']], 'integration', (string) $id);
        return $this->find($id) ?? [];
    }

    public function update(int $id, array $input): array|\WP_Error {
        global $wpdb;
        if (! $this->find($id)) { return new \WP_Error('integration_not_found', __('Integration not found.', 'digiforge'), ['status' => 404]); }
        $data = $this->sanitize_connection($input, false); if (is_wp_error($data)) { return $data; }
        if ($data === []) { return new \WP_Error('integration_empty_update', __('No writable integration fields supplied.', 'digiforge'), ['status' => 400]); }
        $data['updated_at'] = current_time('mysql', true);
        if ($wpdb->update(Tables::integrations(), $data, ['id' => $id]) === false) { return new \WP_Error('integration_update_failed', __('Could not update integration.', 'digiforge'), ['status' => 500]); }
        Logger::audit('integration_updated', ['fields' => array_keys($data)], 'integration', (string) $id);
        return $this->find($id) ?? [];
    }

    public function store_secret(int $integration_id, string $name, string $plaintext): true|\WP_Error {
        global $wpdb;
        if (! $this->find($integration_id)) { return new \WP_Error('integration_not_found', __('Integration not found.', 'digiforge'), ['status' => 404]); }
        $name = sanitize_key($name);
        if ($name === '' || $plaintext === '') { return new \WP_Error('invalid_secret', __('Secret name and value are required.', 'digiforge'), ['status' => 400]); }
        try { $ciphertext = CredentialVault::encrypt($plaintext); } catch (\Throwable $e) { return new \WP_Error('secret_encryption_failed', __('Credential encryption is unavailable.', 'digiforge'), ['status' => 500]); }
        $now = current_time('mysql', true); $fingerprint = CredentialVault::fingerprint($plaintext);
        $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Tables::integration_secrets() . ' WHERE integration_id = %d AND secret_name = %s', $integration_id, $name));
        if ($existing) {
            $ok = $wpdb->update(Tables::integration_secrets(), ['ciphertext' => $ciphertext, 'fingerprint' => $fingerprint, 'updated_at' => $now], ['id' => (int) $existing]);
        } else {
            $ok = $wpdb->insert(Tables::integration_secrets(), ['integration_id' => $integration_id, 'secret_name' => $name, 'ciphertext' => $ciphertext, 'fingerprint' => $fingerprint, 'created_at' => $now, 'updated_at' => $now]);
        }
        if ($ok === false) { return new \WP_Error('secret_store_failed', __('Could not store credential.', 'digiforge'), ['status' => 500]); }
        Logger::audit('integration_secret_stored', ['secret_name' => $name, 'fingerprint' => $fingerprint], 'integration', (string) $integration_id);
        return true;
    }

    public function secret_metadata(int $integration_id): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT secret_name,fingerprint,updated_at FROM ' . Tables::integration_secrets() . ' WHERE integration_id = %d ORDER BY secret_name', $integration_id), ARRAY_A) ?: [];
        return $rows;
    }

    private function sanitize_connection(array $input, bool $create): array|\WP_Error {
        $allowed = $create ? ['provider','connection_key','display_name','status','enabled','config'] : ['display_name','status','enabled','config'];
        foreach ($input as $key => $_) { if (! in_array((string) $key, $allowed, true)) { return new \WP_Error('invalid_integration_field', __('Unsupported integration field.', 'digiforge'), ['status' => 400]); } }
        $out = [];
        if ($create) {
            $provider = sanitize_key((string) ($input['provider'] ?? '')); $key = sanitize_key((string) ($input['connection_key'] ?? ''));
            if (! in_array($provider, self::PROVIDERS, true) || $key === '') { return new \WP_Error('invalid_integration', __('A supported provider and connection key are required.', 'digiforge'), ['status' => 400]); }
            $out['provider'] = $provider; $out['connection_key'] = $key;
        }
        if (array_key_exists('display_name', $input)) { $out['display_name'] = sanitize_text_field((string) $input['display_name']); }
        if ($create && ($out['display_name'] ?? '') === '') { $out['display_name'] = ucwords(str_replace('_', ' ', (string) ($out['connection_key'] ?? ''))); }
        if (array_key_exists('status', $input) || $create) {
            $status = strtoupper(sanitize_key((string) ($input['status'] ?? 'DISCONNECTED')));
            if (! in_array($status, self::STATUSES, true)) { return new \WP_Error('invalid_integration_status', __('Invalid integration status.', 'digiforge'), ['status' => 400]); }
            $out['status'] = $status;
        }
        if (array_key_exists('enabled', $input) || $create) { $out['enabled'] = ! empty($input['enabled']) ? 1 : 0; }
        if (array_key_exists('config', $input)) {
            $config = is_string($input['config']) ? json_decode($input['config'], true) : $input['config'];
            if (! is_array($config)) { return new \WP_Error('invalid_integration_config', __('Integration config must be an object.', 'digiforge'), ['status' => 400]); }
            foreach ($config as $key => $value) { if (preg_match('/secret|token|password|api.?key|credential|authorization/i', (string) $key)) { return new \WP_Error('secret_in_config', __('Secrets must be stored in the credential vault, not integration config.', 'digiforge'), ['status' => 400]); } }
            $out['config'] = wp_json_encode($config);
        } elseif ($create) { $out['config'] = '{}'; }
        return $out;
    }

    private function public_row(array $row): array {
        $row['id'] = (int) $row['id']; $row['enabled'] = (bool) $row['enabled'];
        $row['config'] = json_decode((string) ($row['config'] ?? '{}'), true) ?: [];
        $row['secrets'] = $this->secret_metadata((int) $row['id']);
        return $row;
    }
}
