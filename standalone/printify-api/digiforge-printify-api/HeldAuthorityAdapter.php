<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

use DigiForge\Connector\Printify\NativePortContract;

if (!\defined('ABSPATH')) {
    exit;
}

/** Consumer of protected Core journal evidence. Core remains the durable owner. */
final class HeldAuthorityAdapter
{
    public const CONTRACT = 'DF_STANDALONE_HELD_AUTHORITY_1';

    /** Project a fresh held handoff from Core. No persistence or external exchange. */
    public static function inspect(string $shop, string $account, mixed $scope, array $binding, array $context): array
    {
        $observation = NativeHeldInspection::read($shop, $account, $scope, $binding, $context);
        $result = self::admissionStatus();
        if (($observation['provider_account_binding_verified'] ?? false) !== true) {
            return $result;
        }

        $identityDigest = NativePortContract::hash([
            'contract' => self::CONTRACT,
            'account_reference' => $account,
            'binding' => $binding,
            'session_fingerprint' => $context['session_fingerprint'],
        ]);
        return [
            'journal_state' => $observation['journal_state'],
            'handoff' => [
                'operation_id' => $observation['operation_id'],
                'identity_digest' => $identityDigest,
                'payload_digest' => $binding['payload_fingerprint'],
                'journal_revision' => $observation['journal_revision'],
                'event_hash' => $observation['event_hash'],
            ],
            'reconciliation' => $observation['state'] === 'UNKNOWN' ? 'MANUAL_REVIEW_REQUIRED' : 'NOT_APPLICABLE',
        ] + $result;
    }

    /** Final boundary has no independently qualified registrar or sender. */
    public static function admissionStatus(): array
    {
        return [
            'schema' => self::CONTRACT,
            'journal_state' => 'UNAVAILABLE',
            'handoff' => null,
            'admission' => 'DENIED',
            'dispatch' => 'DISABLED_BY_POLICY',
            'attempt_id' => null,
            'acknowledgment' => 'UNVERIFIED',
            'authority' => 'BLOCKED_EXTERNAL',
            'recovery_floor_verified' => false,
            'reconciliation' => 'BLOCKED',
            'provider_execution_authorized' => false,
            'retry_permitted' => false,
        ];
    }
}
