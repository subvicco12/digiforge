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
        if (is_array($interlock) && $interlock !== [] && ($interlock['state'] ?? '') !== 'SUPERSEDED') {
            $value = self::claim((string) ($interlock['operation_key'] ?? ''));
            $claim = is_array($value) && $value !== [] ? $value : $interlock;
        } elseif (is_array($interlock) && ($interlock['state'] ?? '') === 'SUPERSEDED') {
            $claim = [];
            $record = $interlock;
        }
        if ($claim !== []) { $record = $claim; }

        return [
            'state' => (string) ($record['state'] ?? 'PROVIDER_REQUIRED'),
            'operation_key' => (string) ($record['operation_key'] ?? ''),
            'target_environment' => (string) ($record['target_environment'] ?? ''),
            'target_site_url' => (string) ($record['target_site_url'] ?? ''),
            'database_backup_identifier' => (string) ($record['database_backup_identifier'] ?? ''),
            'backup_identity_operation_key' => (string) ($record['backup_identity_operation_key'] ?? ''),
            'backup_identity_binding_hash' => (string) ($record['backup_identity_binding_hash'] ?? ''),
            'plugin_package_identifier' => (string) ($record['plugin_package_identifier'] ?? ''),
            'plugin_package_version' => self::plannedPackageVersion($record),
            'plugin_package_source_commit' => (string) ($record['plugin_package_source_commit'] ?? ''),
            'plugin_package_sha256' => (string) ($record['plugin_package_sha256'] ?? ''),
            'artifact_evidence_hash' => (string) ($record['artifact_evidence_hash'] ?? ''),
            'provider' => (string) ($record['provider'] ?? ''),
            'provider_operation_reference' => (string) ($record['provider_operation_reference'] ?? ''),
            'reconciliation_required' => (bool) ($record['reconciliation_required'] ?? false),
            'provider_capability_available' => RecoveryProviderRegistry::capabilities() !== [],
            'provider_capabilities' => RecoveryProviderRegistry::capabilities(),
            'updated_at' => (string) ($record['updated_at'] ?? ''),
            'dispatch_state' => (string) ($record['dispatch_state'] ?? 'NOT_DISPATCHED'),
            'manual_restore_reconciled' => (bool) ($record['manual_restore_reconciled'] ?? false),
            'external_actions_performed' => $ledgerUnknown || $claim !== [] ? null : (bool) ($record['external_actions_performed'] ?? false),
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
        $backupIdentityOperationKey = trim(sanitize_text_field((string) ($input['backup_identity_operation_key'] ?? '')));
        if ($operationKey === '' || strlen($operationKey) > 128 || $backupIdentityOperationKey === '' || strlen($backupIdentityOperationKey) > 128 || $targetEnvironment !== 'staging' || $targetSiteUrl === '') {
            return new \WP_Error('digiforge_recovery_plan_invalid', __('Bounded recovery and backup-identity operation keys plus an explicit staging target URL are required.', 'digiforge'), ['status' => 400]);
        }

        $home = trailingslashit(strtolower((string) home_url('/')));
        $target = trailingslashit(strtolower($targetSiteUrl));
        if ($home === $target) {
            return new \WP_Error('digiforge_recovery_target_production', __('Recovery orchestration refuses the current site as its restore target.', 'digiforge'), ['status' => 409]);
        }

        $targetParts = wp_parse_url($targetSiteUrl);
        if (! is_array($targetParts) || ($targetParts['scheme'] ?? '') !== 'https'
            || strtolower((string) ($targetParts['host'] ?? '')) !== 'digiforgestaging.converentis.com'
            || isset($targetParts['port'], $targetParts['user']) || isset($targetParts['pass'])
            || isset($targetParts['query']) || isset($targetParts['fragment'])
            || ! in_array($targetParts['path'] ?? '', ['', '/'], true)) {
            return new \WP_Error('digiforge_recovery_target_invalid', __('Recovery requires the exact isolated HTTPS staging root.', 'digiforge'), ['status' => 409]);
        }

        $artifacts = RecoveryEvidence::snapshot();
        $backup = is_array($artifacts['database_backup'] ?? null) ? $artifacts['database_backup'] : [];
        $package = is_array($artifacts['plugin_package'] ?? null) ? $artifacts['plugin_package'] : [];
        if (($artifacts['database_backup_retrievable'] ?? false) !== true
            || ($artifacts['plugin_package_retrievable'] ?? false) !== true
            || ($artifacts['checksum_verified'] ?? false) !== true) {
            return new \WP_Error('digiforge_recovery_artifacts_unverified', __('Current verified and retrievable recovery artifacts are required.', 'digiforge'), ['status' => 409]);
        }

        $identityBinding = RecoveryBackupIdentityBinding::read($backupIdentityOperationKey);
        if ($identityBinding instanceof \WP_Error) { return $identityBinding; }
        if ($identityBinding === []
            || ($identityBinding['database_identity_proof_available'] ?? false) !== true
            || ! hash_equals((string) ($backup['identifier'] ?? ''), (string) ($identityBinding['backup_identifier'] ?? ''))
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($identityBinding['binding_hash'] ?? ''))) {
            return new \WP_Error('digiforge_recovery_backup_identity_unbound', __('Recovery planning requires immutable identity evidence for the exact current database backup.', 'digiforge'), ['status' => 409]);
        }

        $existing = self::snapshot();
        if ($existing['operation_key'] === $operationKey) {
            if ($existing['target_site_url'] !== $targetSiteUrl
                || $existing['database_backup_identifier'] !== (string) ($backup['identifier'] ?? '')
                || $existing['plugin_package_identifier'] !== (string) ($package['identifier'] ?? '')
                || $existing['plugin_package_version'] !== (string) ($package['version'] ?? '')
                || $existing['backup_identity_operation_key'] !== $backupIdentityOperationKey
                || $existing['backup_identity_binding_hash'] !== (string) ($identityBinding['binding_hash'] ?? '')
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
            'backup_identity_operation_key' => $backupIdentityOperationKey,
            'backup_identity_binding_hash' => (string) ($identityBinding['binding_hash'] ?? ''),
            'plugin_package_identifier' => (string) ($package['identifier'] ?? ''),
            'plugin_package_version' => (string) ($package['version'] ?? ''),
            'plugin_package_source_commit' => (string) ($package['source_commit'] ?? ''),
            'plugin_package_sha256' => (string) ($package['sha256'] ?? ''),
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
        if (! self::canonicalStagingTarget((string) ($record['target_site_url'] ?? ''))) {
            return new \WP_Error('digiforge_recovery_target_invalid', __('Recovery requires the exact isolated HTTPS staging root.', 'digiforge'), ['status' => 409]);
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
        $global = RecoveryDispatchLedger::read('digiforge_recovery_dispatch_interlock');
        $globalClaimed = is_array($global) && $global === []
            ? RecoveryDispatchLedger::insert('digiforge_recovery_dispatch_interlock', $record)
            : (is_array($global) && ($global['state'] ?? '') === 'SUPERSEDED'
                && ($global['retry_permitted'] ?? true) === false
                && RecoveryDispatchLedger::compareAndSwap('digiforge_recovery_dispatch_interlock', $global, $record));
        if (! $globalClaimed
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
            'backup_identity_operation_key' => $record['backup_identity_operation_key'],
            'backup_identity_binding_hash' => $record['backup_identity_binding_hash'],
            'plugin_package_identifier' => $record['plugin_package_identifier'],
            'plugin_package_version' => $record['plugin_package_version'],
            'plugin_package_source_commit' => $record['plugin_package_source_commit'],
            'plugin_package_sha256' => $record['plugin_package_sha256'],
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

    /**
     * Reconcile a staging restore that a human already performed outside DigiForge.
     * This records the external action truthfully; it never dispatches or retries it.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public static function reconcileManualRestore(string $operationKey, string $providerReference, string $confirmation): array|\WP_Error
    {
        if (! self::safetyLocked()) {
            return new \WP_Error('digiforge_recovery_not_locked', __('Manual recovery reconciliation requires STOP ALL and the external safety lock.', 'digiforge'), ['status' => 409]);
        }
        $record = self::snapshot();
        if ($operationKey === '' || ! hash_equals((string) $record['operation_key'], $operationKey)) {
            return new \WP_Error('digiforge_recovery_operation_mismatch', __('The recovery operation key does not match the active plan.', 'digiforge'), ['status' => 409]);
        }
        if (! hash_equals('I_CONFIRM_MANUAL_STAGING_RESTORE_COMPLETED', $confirmation)) {
            return new \WP_Error('digiforge_recovery_manual_confirmation_required', __('Explicit confirmation of the completed manual staging restore is required.', 'digiforge'), ['status' => 400]);
        }
        $providerReference = trim(sanitize_text_field($providerReference));
        if ($providerReference === '' || strlen($providerReference) > 190) {
            return new \WP_Error('digiforge_recovery_manual_reference_required', __('A bounded provider/manual restore reference is required.', 'digiforge'), ['status' => 400]);
        }
        if (! self::canonicalStagingTarget((string) ($record['target_site_url'] ?? ''))) {
            return new \WP_Error('digiforge_recovery_target_invalid', __('Recovery requires the exact isolated HTTPS staging root.', 'digiforge'), ['status' => 409]);
        }
        if (($record['manual_restore_reconciled'] ?? false) === true) {
            if (hash_equals((string) ($record['provider_operation_reference'] ?? ''), $providerReference)) {
                return $record + ['replayed' => true];
            }
            return new \WP_Error('digiforge_recovery_manual_reconciliation_conflict', __('The manual restore was already reconciled with different evidence.', 'digiforge'), ['status' => 409]);
        }
        if (self::claim($operationKey) !== [] || ! in_array((string) $record['state'], ['PLANNED', 'PROVIDER_REQUIRED'], true)) {
            return new \WP_Error('digiforge_recovery_reconciliation_required', __('A dispatched or claimed recovery operation cannot be converted into a manual restore record.', 'digiforge'), ['status' => 409, 'reconciliation_required' => true]);
        }
        $artifacts = RecoveryEvidence::snapshot();
        if (($artifacts['database_backup_retrievable'] ?? false) !== true
            || ($artifacts['plugin_package_retrievable'] ?? false) !== true
            || ($artifacts['checksum_verified'] ?? false) !== true
            || ($artifacts['database_backup']['identifier'] ?? '') !== $record['database_backup_identifier']
            || ($artifacts['plugin_package']['identifier'] ?? '') !== $record['plugin_package_identifier']
            || self::artifactEvidenceHash($artifacts) !== $record['artifact_evidence_hash']) {
            return new \WP_Error('digiforge_recovery_artifacts_unverified', __('Manual restore reconciliation requires the exact still-verified planned artifacts.', 'digiforge'), ['status' => 409]);
        }
        $stored = [
            'state' => 'VERIFY_REQUIRED',
            'operation_key' => $record['operation_key'],
            'target_environment' => $record['target_environment'],
            'target_site_url' => $record['target_site_url'],
            'database_backup_identifier' => $record['database_backup_identifier'],
            'backup_identity_operation_key' => $record['backup_identity_operation_key'],
            'backup_identity_binding_hash' => $record['backup_identity_binding_hash'],
            'plugin_package_identifier' => $record['plugin_package_identifier'],
            'plugin_package_version' => $record['plugin_package_version'],
            'plugin_package_source_commit' => $record['plugin_package_source_commit'],
            'plugin_package_sha256' => $record['plugin_package_sha256'],
            'artifact_evidence_hash' => $record['artifact_evidence_hash'],
            'provider' => 'hostinger',
            'provider_operation_reference' => $providerReference,
            'dispatch_state' => 'MANUAL_RESTORE_RECONCILED',
            'manual_restore_reconciled' => true,
            'reconciliation_required' => true,
            'external_actions_performed' => true,
            'updated_at' => gmdate('c'),
        ];
        if (! update_option(self::OPTION, $stored, false) || get_option(self::OPTION) !== $stored) {
            return new \WP_Error('digiforge_recovery_manual_reconciliation_persist_failed', __('Unable to persist manual restore reconciliation evidence.', 'digiforge'), ['status' => 500, 'reconciliation_required' => true]);
        }
        return self::snapshot() + ['replayed' => false];
    }

    /** Terminal handoff for an irrecoverably stale reconciled operation; never deletes its claim or archive. */
    public static function terminallySupersede(string $operationKey, array $receipt): bool
    {
        if (! self::safetyLocked() || $operationKey === '' || ($receipt['state'] ?? '') !== 'SUPERSEDED'
            || ! hash_equals($operationKey, (string)($receipt['operation_key'] ?? ''))
            || ($receipt['retry_permitted'] ?? true) !== false
            || ($receipt['reconciliation_required'] ?? true) !== false) {
            return false;
        }
        $current = self::snapshot();
        $interlock = RecoveryDispatchLedger::read('digiforge_recovery_dispatch_interlock');
        if ($interlock instanceof \WP_Error
            || ($current['manual_restore_reconciled']??false)!==true
            || ($current['state']??'')!=='VERIFY_REQUIRED'
            || !hash_equals($operationKey,(string)($current['operation_key']??''))) {
            return false;
        }
        $terminal = $receipt + [
            'dispatch_state'=>'SUPERSEDED',
            'manual_restore_reconciled'=>true,
            'provider'=>(string)($current['provider']??'hostinger'),
            'provider_operation_reference'=>(string)($current['provider_operation_reference']??''),
            'updated_at'=>(string)($receipt['superseded_at']??gmdate('c')),
        ];
        if ($interlock === []) {
            // Legacy manually reconciled drills can predate the global interlock.
            // Claim the empty slot atomically with the immutable terminal receipt;
            // never manufacture or delete an executable recovery claim.
            if (!RecoveryDispatchLedger::insert('digiforge_recovery_dispatch_interlock',$terminal)) {
                $committed=RecoveryDispatchLedger::read('digiforge_recovery_dispatch_interlock');
                if ($committed instanceof \WP_Error || $committed!==$terminal) return false;
            }
        } else {
            if (!hash_equals($operationKey,(string)($interlock['operation_key']??''))) return false;
            if (($interlock['state']??'')==='SUPERSEDED') {
                if ($interlock!==$terminal) return false;
            } elseif (!RecoveryDispatchLedger::compareAndSwap('digiforge_recovery_dispatch_interlock',$interlock,$terminal)) {
                return false;
            }
        }
        $stored=get_option(self::OPTION);
        if ($stored===$terminal) return true;
        return update_option(self::OPTION,$terminal,false) && get_option(self::OPTION)===$terminal;
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

    /** Resolve the package version from immutable plan data; legacy plans derive it only from their frozen package identifier. */
    public static function plannedPackageVersion(array $record): string
    {
        $version = trim((string) ($record['plugin_package_version'] ?? ''));
        if ($version !== '') { return $version; }
        $identifier = (string) ($record['plugin_package_identifier'] ?? '');
        if (preg_match('/(?:^|-)v(\\d+\\.\\d+\\.\\d+)(?:-|$)/', $identifier, $matches) === 1) {
            return (string) $matches[1];
        }
        return '';
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
    private static function canonicalStagingTarget(string $url): bool
    {
        $parts = wp_parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'digiforgestaging.converentis.com'
            && ! isset($parts['port']) && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment'])
            && in_array($parts['path'] ?? '', ['', '/'], true);
    }

}
