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

    /**
     * Join signed inert sender evidence to a fresh native UNKNOWN readback.
     * A fixture pin or matching payload never establishes actual sender admission.
     */
    public static function reviewSenderEvidence(
        string $shop, string $account, array $receipt, int $credentialReference,
        string $payload, string $wire, array $pin, array $expected, int $now,
        ?array $dispatchReceipt = null, ?array $recoveryReceipt = null
    ): array {
        $held = [
            'schema'=>'DF_STANDALONE_HELD_SENDER_REVIEW_1',
            'state'=>'HELD', 'reason'=>'NATIVE_RECEIPT_OR_SCOPE_UNAVAILABLE',
            'sender_evidence_verified'=>false, 'local_receipt_reference'=>null,
            'dispatch_receipt_verified'=>false, 'independent_floor_verified'=>false,
            'dispatch_contract_matches'=>false, 'recovery_contract_matches'=>false,
            'provider_execution_authorized'=>false, 'retry_permitted'=>false,
        ];
        if (($receipt['action'] ?? null) !== 'RECEIPT_READ') return $held;
        $native = NativeHeldInspection::prepareMutation($shop,$account,$receipt);
        if (($native['state'] ?? null) !== 'UNKNOWN') return $held;
        $held['state']='UNKNOWN';
        $held['local_receipt_reference']=$receipt['data']['receipt_reference'];
        $binding = $receipt['binding'];
        $joined = [
            'operation_id'=>$binding['operation_id'], 'shop'=>$shop, 'account'=>$account,
            'integration'=>(string)$binding['integration_id'], 'actor'=>$binding['actor_id'],
            'authorization_revision'=>$binding['authorization_revision'],
            'payload_digest'=>$binding['payload_fingerprint'],
            'idempotency_id'=>$receipt['duplicate_key'],
            'journal_revision'=>$native['journal_revision'], 'event_hash'=>$receipt['data']['receipt_reference'],
        ];
        // The current native scope verifies the reference. The sender vector carries no credential material.
        if ($credentialReference !== $binding['credential_reference']) {
            $held['reason']='SENDER_BINDING_MISMATCH';
            return $held;
        }
        foreach ($joined as $field=>$value) {
            if (($expected[$field] ?? null) !== $value) {
                $held['reason']='SENDER_BINDING_MISMATCH';
                return $held;
            }
        }
        $held['reason']='SENDER_EVIDENCE_UNVERIFIED';
        foreach (['key_id','issuer','audience','public_key'] as $field) {
            if (!is_string($pin[$field] ?? null)) return $held;
        }
        if (($pin['revoked'] ?? null) !== false) return $held;
        require_once __DIR__.'/HeldSignedEvidence.php';
        if (!HeldSignedEvidence::matchesPayload($payload,$wire,$pin['key_id'],$pin['issuer'],$pin['audience'],
            $pin['public_key'],false,$now,$expected)) return $held;
        try {
            $held['dispatch_contract_matches'] = $dispatchReceipt !== null
                && self::matchesDispatchContract($dispatchReceipt,$binding,$expected);
            $held['recovery_contract_matches'] = $recoveryReceipt !== null
                && self::matchesRecoveryContract($recoveryReceipt,$binding,$expected);
        } catch (\Throwable) {
            $held['dispatch_contract_matches'] = $held['recovery_contract_matches'] = false;
        }
        return ['reason'=>'INDEPENDENT_SENDER_AND_FLOOR_REQUIRED','sender_evidence_verified'=>true] + $held;
    }

    private static function matchesDispatchContract(array $receipt,array $binding,array $expected): bool
    {
        if (!NativePortContract::valid('provider_receipt',$receipt)
            || $receipt['kind'] !== 'UNKNOWN' || $receipt['http_status'] !== 0 || $receipt['body'] !== []
            || $receipt['sent_at'] > $receipt['received_at'] || $receipt['recovery_epoch'] !== $expected['epoch']
            || $receipt['sequence'] !== $expected['sequence']) return false;
        foreach (['operation_id','payload_fingerprint','intent_fingerprint','scope_fingerprint','approval_reference',
            'shop_key','integration_id','credential_reference','provider_shop_id','authorization_revision','product_version'] as $field) {
            if ($receipt[$field] !== $binding[$field]) return false;
        }
        return self::matchesReceiptDigest($receipt);
    }

    private static function matchesRecoveryContract(array $receipt,array $binding,array $expected): bool
    {
        return NativePortContract::valid('recovery_receipt',$receipt)
            && $receipt['scope_fingerprint'] === $binding['scope_fingerprint'] && $receipt['actor_id'] === $binding['actor_id']
            && $receipt['journal_state'] === 'UNKNOWN' && $receipt['recovery_required']
            && $receipt['recovery_epoch'] === $expected['epoch'] && $receipt['checkpoint_epoch'] === $expected['epoch']
            && $receipt['generation'] === $receipt['checkpoint_generation']
            && (!$receipt['emergency_acknowledged'] || ($receipt['emergency_requested'] && $receipt['pending_leases'] === 0))
            && self::matchesReceiptDigest($receipt);
    }

    private static function matchesReceiptDigest(array $receipt): bool
    {
        return hash_equals($receipt['receipt_hash'],NativePortContract::hash(array_diff_key($receipt,['receipt_hash'=>true])));
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
