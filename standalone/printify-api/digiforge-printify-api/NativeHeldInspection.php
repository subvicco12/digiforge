<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

use DigiForge\Connector\Printify\{
    NativeAuthorityAdapter, NativeBridge, NativeBuildIdentity, NativeCorePort, NativeHeldJournal, NativePanelAccessAdapter, NativePortContract, NativeReadOnlyInspection, ShopBinding
};

if (!\defined('ABSPATH')) {
    exit;
}

/** Read-only access to the reviewed Core's held evidence, never to its writer. */
final class NativeHeldInspection
{
    private const CORE = '2c9fcca6e14fbf64f94414661d42d85715039070';
    private const STAMP = 'b06ad4959429685f54ff5fc754eefa9c80e09b096a2b35c67af6b017e25e67a9';
    private const SPEC = '6d19ac1c9971d5dd730fa910d08b34ad868e7d0f351b5996029dd60322ed3434';

    /** Identify the reviewed installed build without invoking the native port. */
    public static function buildStatus(): string
    {
        try {
            if (!class_exists(NativeBuildIdentity::class)) {
                return 'NATIVE_CORE_UNAVAILABLE';
            }
            return self::reviewedDirectory() === null ? 'NATIVE_BUILD_INCOMPATIBLE' : 'REVIEWED_BUILD_PRESENT';
        } catch (\Throwable) {
            return 'NATIVE_BUILD_INCOMPATIBLE';
        }
    }

    private static function reviewedDirectory(): ?string
    {
        if (!class_exists(NativePortContract::class)) {
            return null;
        }
        $directory = dirname((new \ReflectionClass(NativeBuildIdentity::class))->getFileName());
        $stamp = @file_get_contents($directory . '/native-build.json', false, null, 0, 65537);
        if (!is_string($stamp) || !hash_equals(self::STAMP, hash('sha256', $stamp))
            || NativeBuildIdentity::source() !== self::CORE || NativePortContract::digest() !== self::SPEC
            || (new \ReflectionClass(NativePortContract::class))->getFileName() !== $directory . '/NativePortContract.php') {
            return null;
        }
        return $directory;
    }

    public static function read(string $shop, string $account, mixed $scope, array $binding, array $context): array
    {
        if (!Runtime::permitsFixtureAccount($shop, $account)) {
            return self::result('DENIED', 'OPERATOR_SCOPE');
        }
        try {
            if (!class_exists(NativeBuildIdentity::class)) {
                return self::result('BLOCKED', 'NATIVE_CORE_UNAVAILABLE');
            }
            $directory = self::reviewedDirectory();
            if ($directory === null
                || (new \ReflectionClass(NativeReadOnlyInspection::class))->getFileName() !== $directory . '/NativeReadOnlyInspection.php'
                || (new \ReflectionClass(NativeHeldJournal::class))->getFileName() !== $directory . '/NativeHeldJournal.php') {
                return self::result('BLOCKED', 'NATIVE_BUILD_INCOMPATIBLE');
            }
            $actor = \get_current_user_id();
            if (!$scope instanceof ShopBinding || $scope->environment !== 'test' || $scope->providerShopId !== $shop
                || $actor < 1 || !NativePortContract::valid('binding', $binding)
                || $binding['actor_id'] !== $actor || $binding['scope_fingerprint'] !== $scope->fingerprint()
                || $binding['shop_id'] !== $scope->shopId || $binding['shop_key'] !== $scope->shopKey
                || $binding['provider_shop_id'] !== $scope->providerShopId
                || $binding['integration_id'] !== $scope->integrationId
                || $binding['credential_reference'] !== $scope->credential->integrationId
                || $binding['environment'] !== $scope->environment
                || $binding['authorization_revision'] !== $scope->revision
                || !NativePortContract::valid('panel_context', $context) || $context['view'] !== 'unknown') {
                return self::result('DENIED', 'NATIVE_BINDING');
            }
            $inspection = NativeReadOnlyInspection::snapshot($scope, $actor, $context,
                ['kind' => 'HELD_OPERATION', 'operation_id' => $binding['operation_id']]);
            if (!is_array($inspection)) {
                return self::result('DENIED', 'NATIVE_INSPECTION_DENIED');
            }
            // Join the protected projection with the exact persisted binding.
            // A change between reads fails closed. The journal owns both reads.
            $journal = (new NativeHeldJournal())->read($binding['operation_id'], $scope->shopKey, $actor);
            $observation = $inspection['observation'];
            if (!is_array($journal) || $journal['event_hash'] !== $observation['event_hash']
                || $journal['record']['revision'] !== $observation['journal_revision']
                || $journal['record']['state'] !== $observation['state']
                || NativePortContract::hash($journal['record']['binding']) !== NativePortContract::hash($binding)
                || !in_array($observation['state'], ['HELD', 'FENCED', 'UNKNOWN', 'CANCELLED'], true)) {
                return self::result('BLOCKED', 'EXACT_JOURNAL_READBACK_UNAVAILABLE');
            }
            if (!is_array(NativePanelAccessAdapter::access($scope, $actor, $context))) {
                return self::result('DENIED', 'NATIVE_INSPECTION_DENIED');
            }
            $current = NativeAuthorityAdapter::scope($scope, $actor, ['manage_digiforge_pod']);
            if (!is_array($current) || ($current['scope']['external_account_reference'] ?? null) !== $account
                || ($current['session_fingerprint'] ?? null) !== $context['session_fingerprint']) {
                return self::result('DENIED', 'NATIVE_ACCOUNT_OR_SESSION');
            }
            return self::result($observation['state'] === 'UNKNOWN' ? 'UNKNOWN' : 'LOCAL_HELD_OBSERVED',
                'MANUAL_REVIEW_REQUIRED') + [
                    'provider_account_binding_verified' => true,
                    'operation_id' => $binding['operation_id'],
                    'journal_state' => $observation['state'],
                    'journal_revision' => $observation['journal_revision'],
                    'event_hash' => $observation['event_hash'],
                ];
        } catch (\Throwable) {
            return self::result('BLOCKED', 'NATIVE_INSPECTION_UNAVAILABLE');
        }
    }

    /** Check the canonical request for handoff only. No Core writer is invoked. */
    public static function prepareMutation(string $shop,string $account,array $request): array
    {
        $binding = $request['binding'] ?? null;
        if (!Runtime::permitsFixtureAccount($shop, $account)
            || !in_array($request['action'] ?? null, ['AUTHORIZE','REGISTER','CLAIM','RECOVER','CANCEL','RECEIPT_READ','RECONCILE','OUTCOME'], true)
            || !is_array($binding)
            || ($binding['provider_shop_id'] ?? null) !== $shop
            || ($binding['environment'] ?? null) !== 'test') {
            return self::result('DENIED','NATIVE_BINDING');
        }
        try {
            if (!class_exists(NativeBuildIdentity::class) || !class_exists(NativePortContract::class)) {
                return self::result('BLOCKED','NATIVE_CORE_UNAVAILABLE');
            }
            $directory = self::reviewedDirectory();
            if ($directory === null
                || (new \ReflectionClass(NativeAuthorityAdapter::class))->getFileName() !== $directory . '/NativeAuthorityAdapter.php') {
                return self::result('BLOCKED','NATIVE_BUILD_INCOMPATIBLE');
            }
            if (!NativePortContract::valid('mutation_request',$request)
                || ($binding['actor_id'] ?? null) !== \get_current_user_id()) {
                return self::result('DENIED','NATIVE_BINDING');
            }
            foreach (['actor_id', 'authorization_revision', 'approval_reference', 'product_version', 'expected_product_version'] as $field) {
                if ($binding[$field] !== $request['context'][$field]) {
                    return self::result('DENIED', 'NATIVE_BINDING');
                }
            }
            $actor = $binding['actor_id'];
            $scope = NativeAuthorityAdapter::mutationScope($binding);
            if (!$scope instanceof ShopBinding) {
                return self::result('DENIED', 'NATIVE_BINDING');
            }
            $current = NativeAuthorityAdapter::scope($scope, $actor, ['manage_digiforge_pod']);
            if (!is_array($current) || ($current['scope']['external_account_reference'] ?? null) !== $account) {
                return self::result('DENIED', 'NATIVE_ACCOUNT_OR_SESSION');
            }
            if (in_array($request['action'], ['AUTHORIZE','RECEIPT_READ'], true)) {
                if ((new \ReflectionClass(NativeBridge::class))->getFileName() !== $directory . '/NativeBridge.php'
                    || (new \ReflectionClass(NativeCorePort::class))->getFileName() !== $directory . '/NativeCorePort.php') {
                    return self::result('BLOCKED', 'NATIVE_BUILD_INCOMPATIBLE');
                }
                $core = NativeBridge::connect('1.0.0');
                if (!$core instanceof NativeCorePort) {
                    return self::result('BLOCKED', 'NATIVE_COMPANION_INCOMPATIBLE');
                }
                // Both actions inspect current local evidence. The writer is never delegated.
                $decision = $core->exchange($request);
                if (!$decision instanceof \WP_Error) {
                    return self::result('BLOCKED', 'UNEXPECTED_NATIVE_AUTHORITY_RESULT');
                }
                if ($request['action'] === 'RECEIPT_READ') {
                    $evidence = $decision->get_error_data();
                    if ($decision->get_error_code() !== 'printify_native_held_outcome_unknown' || !is_array($evidence)
                        || ($evidence['outcome'] ?? null) !== 'UNKNOWN'
                        || ($evidence['provider_receipt_verified'] ?? null) !== false
                        || ($evidence['retry_permitted'] ?? null) !== false
                        || ($evidence['external_execution_authorized'] ?? null) !== false
                        || !is_int($evidence['journal_revision'] ?? null) || $evidence['journal_revision'] < 1) {
                        return self::result('DENIED', 'NATIVE_RECEIPT_UNAVAILABLE');
                    }
                    return self::result('UNKNOWN', 'NATIVE_HELD_OUTCOME_UNKNOWN') + [
                        'canonical_action'=>'RECEIPT_READ','canonical_request_shape_verified'=>true,
                        'journal_revision'=>$evidence['journal_revision'],'provider_receipt_verified'=>false,
                    ];
                }
                if (!in_array($decision->get_error_code(), [
                    'printify_native_journal_and_independent_checkpoint_required',
                    'printify_native_product_revision_authority_required',
                ], true)) {
                    return self::result('DENIED', 'NATIVE_AUTHORIZATION_DENIED');
                }
            }
            return self::result('BLOCKED','CURRENT_NATIVE_OPERATIONAL_AUTHORITY_UNAVAILABLE')+[
                'canonical_action'=>$request['action'],'canonical_request_shape_verified'=>true,
            ];
        } catch (\Throwable) {
            return self::result('BLOCKED','NATIVE_CONTRACT_UNAVAILABLE');
        }
    }

    private static function result(string $state, string $reason): array
    {
        return ['schema' => 'DF_STANDALONE_NATIVE_HELD_INSPECTION_1', 'state' => $state, 'reason' => $reason,
            'approval_verified' => false, 'product_revision_verified' => false,
            'register' => 'BLOCKED', 'claim' => 'BLOCKED', 'reconciliation' => 'BLOCKED', 'recovery' => 'BLOCKED',
            'provider_outcome_confirmed' => false, 'provider_execution_authorized' => false, 'retry_permitted' => false];
    }
}
