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
    private const ALLOWED_STATES = ['PLANNED', 'PROVIDER_REQUIRED', 'IN_PROGRESS', 'VERIFY_REQUIRED', 'FAILED', 'UNKNOWN'];

    /** @return array<string,mixed> */
    public static function snapshot(): array
    {
        $value = get_option(self::OPTION, []);
        $record = is_array($value) ? $value : [];

        $ledgerUnknown = false;
        $claim = self::claim((string) ($record['operation_key'] ?? ''));
        $interlock = RecoveryDispatchLedger::read('digiforge_recovery_dispatch_interlock');
        if ($interlock instanceof \WP_Error || $claim instanceof \WP_Error) {
            $ledgerUnknown = true;
            $record['state'] = 'UNKNOWN';
            $record['dispatch_state'] = 'RECONCILIATION_REQUIRED';
            $record['reconciliation_required'] = true;
            $claim = [];
            $interlock = [];
        }
        if (is_array($interlock) && $interlock !== []) {
            $value = self::claim((string) ($interlock['operation_key'] ?? ''));
            $claim = is_array($value) && $value !== [] ? $value : $interlock;
        }
        if ($claim !== []) { $record = $claim; }

        return [
            'state' => (string) ($record['state'] ?? 'PROVIDER_REQUIRED'),
            'operation_key' => (string) ($record['operation_key'] ?? ''),
            'target_environment' => (string) ($record['target_environment'] ?? ''),
            'target_site_url' => (string) ($record['target_site_url'] ?? ''),
            'database_backup_identifier' => (string) ($record['database_backup_identifier'] ?? ''),
            'plugin_package_identifier' => (string) ($record['plugin_package_identifier'] ?? ''),
            'artifact_evidence_hash' => (string) ($record['artifact_evidence_hash'] ?? ''),
            'provider' => (string) ($record['provider'] ?? ''),
            'provider_operation_reference' => (string) ($record['provider_operation_reference'] ?? ''),
            'reconciliation_required' => (bool) ($record['reconciliation_required'] ?? false),
            'provider_capability_available' => RecoveryProviderRegistry::capabilities() !== [],
            'provider_capabilities' => RecoveryProviderRegistry::capabilities(),
            'updated_at' => (string) ($record['updated_at'] ?? ''),
            'dispatch_state' => (string) ($record['dispatch_state'] ?? 'NOT_DISPATCHED'),
            'external_actions_performed' => $ledgerUnknown || $claim !== [] ? null : false,
            'external_execution_authorized' => false,
            'commerce_execution_authorized' => false,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
    public static function plan(array $input): array|\WP_Error
    {
        if (! self::safetyLocked()) {
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
                || $existing['plugin_package_identifier'] !== (string) ($package['identifier'] ?? '')
                || $existing['artifact_evidence_hash'] !== self::artifactEvidenceHash($artifacts)) {
                return new \WP_Error('digiforge_recovery_idempotency_conflict', __('The recovery operation key is already bound to different inputs.', 'digiforge'), ['status' => 409]);
            }
            return $existing + ['replayed' => true];
        }

        if (self::claim($operationKey) !== [] || ! empty($existing['reconciliation_required'])) {
            return new \WP_Error('digiforge_recovery_reconciliation_required', __('A claimed recovery operation cannot be replaced or retried before reconciliation.', 'digiforge'), ['status' => 409, 'reconciliation_required' => true]);
        }

        $record = [
            'state' => RecoveryProviderRegistry::capabilities() !== [] ? 'PLANNED' : 'PROVIDER_REQUIRED',
            'operation_key' => $operationKey,
            'target_environment' => 'staging',
            'target_site_url' => $targetSiteUrl,
            'database_backup_identifier' => (string) ($backup['identifier'] ?? ''),
            'plugin_package_identifier' => (string) ($package['identifier'] ?? ''),
            'artifact_evidence_hash' => self::artifactEvidenceHash($artifacts),
            'provider' => '',
            'provider_operation_reference' => '',
            'reconciliation_required' => false,
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
        if (! self::safetyLocked()) {
            return new \WP_Error('digiforge_recovery_not_locked', __('Recovery execution requires STOP ALL and the external safety lock.', 'digiforge'), ['status' => 409]);
        }

        $record = self::snapshot();
        if ($operationKey === '' || ! hash_equals($record['operation_key'], $operationKey)) {
            return new \WP_Error('digiforge_recovery_operation_mismatch', __('The recovery operation key does not match the active plan.', 'digiforge'), ['status' => 409]);
        }
        if (self::claim($operationKey) !== [] || ! in_array($record['state'], ['PLANNED', 'PROVIDER_REQUIRED'], true)) {
            return new \WP_Error('digiforge_recovery_reconciliation_required', __('Recovery dispatch was already claimed. Reconciliation is required; never retry blindly.', 'digiforge'), ['status' => 409, 'reconciliation_required' => true]);
        }
        $artifacts = RecoveryEvidence::snapshot();
        if (($artifacts['database_backup_retrievable'] ?? false) !== true
            || ($artifacts['plugin_package_retrievable'] ?? false) !== true
            || ($artifacts['checksum_verified'] ?? false) !== true
            || ($artifacts['database_backup']['identifier'] ?? '') !== $record['database_backup_identifier']
            || ($artifacts['plugin_package']['identifier'] ?? '') !== $record['plugin_package_identifier']
            || self::artifactEvidenceHash($artifacts) !== $record['artifact_evidence_hash']
            || ! RecoveryDispatchLedger::artifactsMatch($record['artifact_evidence_hash'])) {
            return new \WP_Error('digiforge_recovery_artifacts_unverified', __('Recovery artifacts changed or are no longer verified.', 'digiforge'), ['status' => 409]);
        }
        $capabilities = RecoveryProviderRegistry::capabilities();
        $providerSlug = sanitize_key((string) ($record['provider'] ?? ''));
        if ($providerSlug === '' && count($capabilities) === 1) {
            $providerSlug = (string) array_key_first($capabilities);
        }
        $provider = RecoveryProviderRegistry::get($providerSlug);
        if ($provider === null || (($capabilities[$providerSlug]['available'] ?? false) !== true)) {
            return new \WP_Error('digiforge_recovery_provider_required', __('No available governed recovery provider adapter is registered.', 'digiforge'), ['status' => 501, 'state' => 'PROVIDER_REQUIRED']);
        }

        // Atomic, permanent tombstone: even a crash before HTTP conservatively blocks redispatch.
        $record['provider'] = $providerSlug;
        $record['state'] = 'UNKNOWN';
        $record['dispatch_state'] = 'RECONCILIATION_REQUIRED';
        $record['reconciliation_required'] = true;
        $record['updated_at'] = gmdate('c');
        if (! RecoveryDispatchLedger::insert('digiforge_recovery_dispatch_interlock', $record)
            || RecoveryDispatchLedger::read('digiforge_recovery_dispatch_interlock') !== $record
            || ! RecoveryDispatchLedger::insert(self::claimOption($operationKey), $record)
            || self::claim($operationKey) !== $record
            || ! update_option(self::OPTION, $record, false)
            || get_option(self::OPTION) !== $record) {
            return new \WP_Error('digiforge_recovery_execution_persist_failed', __('Unable to persist the dispatch claim. No dispatch is permitted; reconcile any existing claim.', 'digiforge'), ['status' => 500, 'reconciliation_required' => true]);
        }
        try { $result = $provider->execute($record); }
        catch (\Throwable $e) {
            return new \WP_Error('digiforge_recovery_result_unknown', __('Recovery provider result is unknown. Reconciliation is required.', 'digiforge'), ['status' => 502, 'reconciliation_required' => true]);
        }
        if ($result instanceof \WP_Error) { return $result; }

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
            'artifact_evidence_hash' => $record['artifact_evidence_hash'],
            'provider' => $providerSlug,
            'dispatch_state' => 'RECONCILIATION_REQUIRED',
            'provider_operation_reference' => sanitize_text_field((string) ($result['provider_operation_reference'] ?? '')),
            'reconciliation_required' => true,
            'updated_at' => gmdate('c'),
        ];
        if (! update_option(self::OPTION, $stored, false)
            || ! RecoveryDispatchLedger::update(self::claimOption($operationKey), $stored)) {
            return new \WP_Error('digiforge_recovery_execution_persist_failed', __('Provider execution returned a result, but DigiForge could not persist it. Reconciliation is required before any retry.', 'digiforge'), ['status' => 500, 'reconciliation_required' => true]);
        }

        return self::snapshot();
    }

    /** Permanent operation identity survives plan replacement and process crashes. */
    private static function claimOption(string $key): string
    {
        return 'digiforge_recovery_claim_' . hash('sha256', $key);
    }

    private static function claim(string $key): array|\WP_Error
    {
        if ($key === '') { return []; }
        return RecoveryDispatchLedger::read(self::claimOption($key));
    }

    public static function safetyLocked(): bool
    {
        return Settings::safety_locked() && Settings::get('stop_all', true) === true
            && Settings::get('activation_authorized', true) === false
            && Settings::get('automation_armed', true) === false
            && RecoveryDispatchLedger::safetyLocked();
    }

    public static function artifactEvidenceHash(array $artifacts): string
    {
        return hash('sha256', (string) wp_json_encode([$artifacts['database_backup'] ?? [], $artifacts['plugin_package'] ?? []]));
    }

    /** Only the exact persisted dispatch claim may reach a provider transport. */
    public static function dispatchClaimMatches(array $plan): bool
    {
        return ($plan['state'] ?? '') === 'UNKNOWN'
            && ($plan['provider'] ?? '') === 'hostinger'
            && ($plan['reconciliation_required'] ?? false) === true
            && self::claim((string) ($plan['operation_key'] ?? '')) === $plan
            && RecoveryDispatchLedger::read('digiforge_recovery_dispatch_interlock') === $plan;
    }
}
