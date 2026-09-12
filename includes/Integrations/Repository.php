<?php
declare(strict_types=1);
namespace DigiForge\Integrations;

use DigiForge\Database\Tables;
use DigiForge\Security\Logger;

/** Local-only integration registry. No provider network requests are performed. */
final class Repository {
    public const PROVIDERS = ['etsy','printify','gelato','ai'];
    public const ENVIRONMENTS = ['sandbox','test','production'];
    public const STATUSES = ['DISCONNECTED','CONFIGURED','PAUSED','ERROR'];

    public function all(int $page = 1, int $perPage = 50): array {
        global $wpdb;
        $page = max(1, $page); $perPage = min(100, max(1, $perPage)); $offset = ($page - 1) * $perPage;
        $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Tables::integrations());
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Tables::integrations() . ' ORDER BY provider, environment, display_name LIMIT %d OFFSET %d', $perPage, $offset), ARRAY_A) ?: [];
        return ['items' => array_map([$this, 'publicRow'], $rows), 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => max(1, (int) ceil($total / $perPage))]];
    }

    public function find(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Tables::integrations() . ' WHERE id = %d', $id), ARRAY_A);
        return is_array($row) ? $this->publicRow($row) : null;
    }

    public function create(array $input): array|\WP_Error {
        global $wpdb;
        $data = $this->sanitizeConnection($input, true); if (is_wp_error($data)) { return $data; }
        $now = current_time('mysql', true);
        $ok = $wpdb->insert(Tables::integrations(), $data + ['created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now]);
        if ($ok === false) { return new \WP_Error('integration_create_failed', __('Could not create integration.', 'digiforge'), ['status' => 409]); }
        $id = (int) $wpdb->insert_id;
        Logger::audit('integration_created', ['provider' => $data['provider'], 'environment' => $data['environment']], 'integration', (string) $id);
        return $this->find($id) ?? [];
    }

    public function update(int $id, array $input): array|\WP_Error {
        global $wpdb;
        if (! $this->find($id)) { return new \WP_Error('integration_not_found', __('Integration not found.', 'digiforge'), ['status' => 404]); }
        $data = $this->sanitizeConnection($input, false); if (is_wp_error($data)) { return $data; }
        if ($data === []) { return new \WP_Error('integration_empty_update', __('No writable integration fields supplied.', 'digiforge'), ['status' => 400]); }
        $data['updated_at'] = current_time('mysql', true);
        if ($wpdb->update(Tables::integrations(), $data, ['id' => $id]) === false) { return new \WP_Error('integration_update_failed', __('Could not update integration.', 'digiforge'), ['status' => 500]); }
        Logger::audit('integration_updated', ['fields' => array_keys($data)], 'integration', (string) $id);
        return $this->find($id) ?? [];
    }

    public function storeSecret(int $integrationId, string $name, string $plaintext): true|\WP_Error {
        global $wpdb;
        if (! $this->find($integrationId)) { return new \WP_Error('integration_not_found', __('Integration not found.', 'digiforge'), ['status' => 404]); }
        $name = sanitize_key($name);
        if ($name === '' || $plaintext === '') { return new \WP_Error('invalid_secret', __('Secret name and value are required.', 'digiforge'), ['status' => 400]); }
        $context = self::secretContext($integrationId, $name);
        try { $ciphertext = CredentialVault::encrypt($plaintext, $context); $fingerprint = CredentialVault::fingerprint($plaintext, $context); }
        catch (\Throwable $e) { return new \WP_Error('secret_encryption_failed', __('Credential encryption is unavailable.', 'digiforge'), ['status' => 500]); }
        $now = current_time('mysql', true);
        $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Tables::integration_secrets() . ' WHERE integration_id = %d AND secret_name = %s', $integrationId, $name));
        $data = ['ciphertext' => $ciphertext, 'fingerprint' => $fingerprint, 'updated_at' => $now];
        $ok = $existing ? $wpdb->update(Tables::integration_secrets(), $data, ['id' => (int) $existing]) : $wpdb->insert(Tables::integration_secrets(), $data + ['integration_id' => $integrationId, 'secret_name' => $name, 'created_at' => $now]);
        if ($ok === false) { return new \WP_Error('secret_store_failed', __('Could not store credential.', 'digiforge'), ['status' => 500]); }
        Logger::audit('integration_secret_stored', ['secret_name' => $name, 'fingerprint' => $fingerprint], 'integration', (string) $integrationId);
        return true;
    }

    public function secretMetadata(int $integrationId): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT secret_name,fingerprint,updated_at FROM ' . Tables::integration_secrets() . ' WHERE integration_id = %d ORDER BY secret_name', $integrationId), ARRAY_A) ?: [];
    }

    public static function secretContext(int $integrationId, string $name): string {
        return 'integration:' . $integrationId . ':secret:' . sanitize_key($name);
    }

    private function containsCredentialField(array $data): bool {
        foreach ($data as $key => $value) {
            if (Logger::isCredentialKey((string) $key)) { return true; }
            if (is_array($value) && $this->containsCredentialField($value)) { return true; }
        }
        return false;
    }

    private function sanitizeConnection(array $input, bool $create): array|\WP_Error {
        $allowed = $create ? ['provider','environment','connection_key','display_name','status','enabled','config'] : ['display_name','status','enabled','config'];
        foreach ($input as $key => $_) { if (! in_array((string) $key, $allowed, true)) { return new \WP_Error('invalid_integration_field', __('Unsupported integration field.', 'digiforge'), ['status' => 400]); } }
        $out = [];
        if ($create) {
            $provider = sanitize_key((string) ($input['provider'] ?? ''));
            $environment = sanitize_key((string) ($input['environment'] ?? 'sandbox'));
            $connectionKey = sanitize_key((string) ($input['connection_key'] ?? ''));
            if (! in_array($provider, self::PROVIDERS, true) || ! in_array($environment, self::ENVIRONMENTS, true) || $connectionKey === '') { return new \WP_Error('invalid_integration', __('A supported provider, environment, and connection key are required.', 'digiforge'), ['status' => 400]); }
            $out['provider'] = $provider; $out['environment'] = $environment; $out['connection_key'] = $connectionKey;
        }
        if (array_key_exists('display_name', $input)) { $out['display_name'] = sanitize_text_field((string) $input['display_name']); }
        if ($create && ($out['display_name'] ?? '') === '') { $out['display_name'] = ucwords(str_replace('_', ' ', (string) $out['connection_key'])); }
        if (array_key_exists('status', $input) || $create) {
            $status = strtoupper(sanitize_key((string) ($input['status'] ?? 'DISCONNECTED')));
            if (! in_array($status, self::STATUSES, true)) { return new \WP_Error('invalid_integration_status', __('Invalid integration status.', 'digiforge'), ['status' => 400]); }
            $out['status'] = $status;
        }
        if (array_key_exists('enabled', $input) || $create) { $out['enabled'] = ! empty($input['enabled']) ? 1 : 0; }
        if (array_key_exists('config', $input)) {
            $config = is_string($input['config']) ? json_decode($input['config'], true) : $input['config'];
            if (! is_array($config)) { return new \WP_Error('invalid_integration_config', __('Integration config must be an object.', 'digiforge'), ['status' => 400]); }
            if ($this->containsCredentialField($config)) { return new \WP_Error('secret_in_config', __('Secrets must be stored in the credential vault, not integration config.', 'digiforge'), ['status' => 400]); }
            $out['config'] = wp_json_encode($config);
        } elseif ($create) { $out['config'] = '{}'; }
        return $out;
    }

    private function publicRow(array $row): array {
        unset($row['ciphertext']);
        $row['id'] = (int) $row['id']; $row['enabled'] = (bool) $row['enabled'];
        $row['config'] = json_decode((string) ($row['config'] ?? '{}'), true) ?: [];
        $row['secrets'] = $this->secretMetadata((int) $row['id']);
        return $row;
    }
}
