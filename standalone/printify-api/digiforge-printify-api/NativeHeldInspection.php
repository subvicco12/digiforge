<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

use DigiForge\Connector\Printify\{
    NativeAuthorityAdapter, NativeBuildIdentity, NativeHeldJournal, NativePanelAccessAdapter, NativePortContract, NativeReadOnlyInspection, ShopBinding
};

if (!\defined('ABSPATH')) {
    exit;
}

/** Read-only access to the reviewed Core's held evidence, never to its writer. */
final class NativeHeldInspection
{
    private const CORE = 'd64d32159bea4832b962acb6b4c08fa6fbeae910';
    private const STAMP = '5cc0c788b263ee5d45ce6e54e260609772713b70becc3154c5bab683c2ad58bf';
    private const SPEC = '6d19ac1c9971d5dd730fa910d08b34ad868e7d0f351b5996029dd60322ed3434';

    public static function read(string $shop, string $account, mixed $scope, array $binding, array $context): array
    {
        if (!Runtime::permitsFixtureAccount($shop, $account)) {
            return self::result('DENIED', 'OPERATOR_SCOPE');
        }
        try {
            if (!class_exists(NativeBuildIdentity::class)) {
                return self::result('BLOCKED', 'NATIVE_CORE_UNAVAILABLE');
            }
            $directory = dirname((new \ReflectionClass(NativeBuildIdentity::class))->getFileName());
            $stamp = @file_get_contents($directory . '/native-build.json', false, null, 0, 65537);
            if (!is_string($stamp) || !hash_equals(self::STAMP, hash('sha256', $stamp))
                || NativeBuildIdentity::source() !== self::CORE || NativePortContract::digest() !== self::SPEC
                || (new \ReflectionClass(NativeReadOnlyInspection::class))->getFileName() !== $directory . '/NativeReadOnlyInspection.php'
                || (new \ReflectionClass(NativeHeldJournal::class))->getFileName() !== $directory . '/NativeHeldJournal.php') {
                return self::result('BLOCKED', 'NATIVE_BUILD_INCOMPATIBLE');
            }
            $actor = \get_current_user_id();
            if (!$scope instanceof ShopBinding || $scope->environment !== 'test' || $scope->providerShopId !== $shop
                || $actor < 1 || !NativePortContract::valid('binding', $binding)
                || $binding['actor_id'] !== $actor || $binding['scope_fingerprint'] !== $scope->fingerprint()
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

    private static function result(string $state, string $reason): array
    {
        return ['schema' => 'DF_STANDALONE_NATIVE_HELD_INSPECTION_1', 'state' => $state, 'reason' => $reason,
            'approval_verified' => false, 'product_revision_verified' => false,
            'register' => 'BLOCKED', 'claim' => 'BLOCKED', 'reconciliation' => 'BLOCKED', 'recovery' => 'BLOCKED',
            'provider_outcome_confirmed' => false, 'provider_execution_authorized' => false, 'retry_permitted' => false];
    }
}
