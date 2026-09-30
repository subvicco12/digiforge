<?php

declare(strict_types=1);

namespace DigiForge\Operations;

use DigiForge\Core\Settings;

/**
 * Fail-closed recovery orchestration control plane.
 *
 * Infrastructure restoration is delegated only through an explicitly registered
 * provider adapter. DigiForge never treats a plan, request, or callback as proof
 * that a restore succeeded; verified drill evidence remains a separate gate.
 */
final class RecoveryOrchestrator
{
    private const OPTION = 'digiforge_recovery_orchestration';
    private const ALLOWED_STATES = ['PLANNED', 'PROVIDER_REQUIRED', 'IN_PROGRESS', 'VERIFY_REQUIRED', 'FAILED'];

    /** @return array<string,mixed> */
    public static function snapshot(): array
    {
        $value = get_option(self::OPTION, []);
        $record = is_array($value) ? $value : [];

        return [
            'state' => (string) ($record['state'] ?? 'PROVIDER_REQUIRED'),
            'operation_key' => (string) ($record['operation_key'] ?? ''),
            'target_environment' => (string) ($record['target_environment'] ?? ''),
            'target_site_url' => (string) ($record['target_site_url'] ?? ''),
            'database_backup_identifier' => (string) ($record['database_backup_identifier'] ?? ''),
            'plugin_package_identifier' => (string) ($record['plugin_package_identifier'] ?? ''),
            'provider' => (string) ($record['provider'] ?? ''),
            'provider_capability_available' => RecoveryProviderRegistry::capabilities() !== [],
            'provider_capabilities' => RecoveryProviderRegistry::capabilities(),
            'updated_at' => (string) ($record['updated_at'] ?? ''),
            'external_actions_performed' => false,
            'external_execution_authorized' => false,
            'commerce_execution_authorized' => false,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
    public static function plan(array $input): array|\WP_Error
    {
        if (! Settings::safety_locked() || Settings::get('stop_all', true) !== true) {
            return new \WP_Error('digiforge_recovery_not_locked', __('Recovery orchestration requires STOP ALL and the external safety lock.', 'digiforge'), ['status' => 409]);
        }

        $operationKey = trim(sanitize_text_field((string) ($input['operation_key'] ?? '')));
        $targetEnvironment = sanitize_key((string) ($input['target_environment'] ?? ''));
        $targetSiteUrl = esc_url_raw(trim((string) ($input['target_site_url'] ?? '')));
        if ($operationKey === '' || strlen($operationKey) > 128 || $targetEnvironment !== 'staging' || $targetSiteUrl === '') {
            return new \WP_Error('digiforge_recovery_plan_invalid', __('A bounded operation key and an explicit staging target URL are required.', 'digiforge'), ['status' => 400]);
        }

        $home = trailingslashit(strtolower((string) home_url('/')));
        $target = trailingslashit(strtolower($targetSiteUrl));
        if ($home === $target) {
            return new \WP_Error('digiforge_recovery_target_production', __('Recovery orchestration refuses the current site as its restore target.', 'digiforge'), ['status' => 409]);
        }

        $artifacts = RecoveryEvidence::snapshot();
        $backup = is_array($artifacts['database_backup'] ?? null) ? $artifacts['database_backup'] : [];
        $package = is_array($artifacts['plugin_package'] ?? null) ? $artifacts['plugin_package'] : [];
        if (($artifacts['database_backup_retrievable'] ?? false) !== true
            || ($artifacts['plugin_package_retrievable'] ?? false) !== true
            || ($artifacts['checksum_verified'] ?? false) !== true) {
            return new \WP_Error('digiforge_recovery_artifacts_unverified', __('Current verified and retrievable recovery artifacts are required.', 'digiforge'), ['status' => 409]);
        }

        $existing = self::snapshot();
        if ($existing['operation_key'] === $operationKey) {
            if ($existing['target_site_url'] !== $targetSiteUrl
                || $existing['database_backup_identifier'] !== (string) ($backup['identifier'] ?? '')
                || $existing['plugin_package_identifier'] !== (string) ($package['identifier'] ?? '')) {
                return new \WP_Error('digiforge_recovery_idempotency_conflict', __('The recovery operation key is already bound to different inputs.', 'digiforge'), ['status' => 409]);
            }
            return $existing + ['replayed' => true];
        }

        $record = [
            'state' => RecoveryProviderRegistry::capabilities() !== [] ? 'PLANNED' : 'PROVIDER_REQUIRED',
            'operation_key' => $operationKey,
            'target_environment' => 'staging',
            'target_site_url' => $targetSiteUrl,
            'database_backup_identifier' => (string) ($backup['identifier'] ?? ''),
            'plugin_package_identifier' => (string) ($package['identifier'] ?? ''),
            'provider' => '',
            'updated_at' => gmdate('c'),
        ];
        if (! update_option(self::OPTION, $record, false)) {
            return new \WP_Error('digiforge_recovery_plan_persist_failed', __('Unable to persist the recovery orchestration plan.', 'digiforge'), ['status' => 500]);
        }

        return self::snapshot() + ['replayed' => false];
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function execute(string $operationKey): array|\WP_Error
    {
        if (! Settings::safety_locked() || Settings::get('stop_all', true) !== true) {
            return new \WP_Error('digiforge_recovery_not_locked', __('Recovery execution requires STOP ALL and the external safety lock.', 'digiforge'), ['status' => 409]);
        }

        $record = self::snapshot();
        if ($operationKey === '' || ! hash_equals($record['operation_key'], $operationKey)) {
            return new \WP_Error('digiforge_recovery_operation_mismatch', __('The recovery operation key does not match the active plan.', 'digiforge'), ['status' => 409]);
        }
        if (! has_filter('digiforge_recovery_provider_execute')) {
            return new \WP_Error('digiforge_recovery_provider_required', __('No governed recovery provider adapter is registered.', 'digiforge'), ['status' => 501, 'state' => 'PROVIDER_REQUIRED']);
        }

        $result = apply_filters('digiforge_recovery_provider_execute', null, $record);
        if (! is_array($result)) {
            return new \WP_Error('digiforge_recovery_provider_invalid', __('The recovery provider returned no verifiable execution result.', 'digiforge'), ['status' => 502]);
        }

        $providerState = sanitize_key((string) ($result['state'] ?? ''));
        $stateMap = ['in_progress' => 'IN_PROGRESS', 'verify_required' => 'VERIFY_REQUIRED', 'failed' => 'FAILED'];
        $state = $stateMap[$providerState] ?? '';
        if ($state === '' || ! in_array($state, self::ALLOWED_STATES, true)) {
            return new \WP_Error('digiforge_recovery_provider_state', __('The recovery provider returned an unsupported state.', 'digiforge'), ['status' => 502]);
        }

        $stored = [
            'state' => $state,
            'operation_key' => $record['operation_key'],
            'target_environment' => $record['target_environment'],
            'target_site_url' => $record['target_site_url'],
            'database_backup_identifier' => $record['database_backup_identifier'],
            'plugin_package_identifier' => $record['plugin_package_identifier'],
            'provider' => sanitize_key((string) ($result['provider'] ?? '')),
            'updated_at' => gmdate('c'),
        ];
        update_option(self::OPTION, $stored, false);

        return self::snapshot();
    }
}
