<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Validates the one-way handoff from a consumed controlled-execution permit
 * to a future Etsy adapter invocation.
 *
 * This contract is deliberately local-only. It neither invokes an adapter nor
 * mutates the operation ledger. A consumed permit must never be treated as
 * proof that an external request was sent.
 */
final class EtsyConsumedPermitHandoff
{
    /** @return array<string,mixed>|WP_Error */
    public static function validate(array $prepared): array|WP_Error
    {
        if (($prepared['state'] ?? '') !== 'ETSY_OPERATION_PREPARED') {
            return self::error('invalid_state', 'A prepared Etsy operation is required.');
        }
        $operationId = (int)($prepared['operation_id'] ?? 0);
        if ($operationId < 1) {
            return self::error('invalid_operation', 'A valid Etsy operation id is required.');
        }
        $permit = $prepared['permit'] ?? null;
        if (!is_array($permit)
            || ($permit['state'] ?? '') !== 'ADAPTER_CALL_PERMITTED'
            || ($permit['nonce_consumed'] ?? null) !== true) {
            return self::error('invalid_permit', 'A consumed controlled-execution permit is required.');
        }
        if (($permit['external_execution_performed'] ?? null) !== false
            || ($prepared['adapter_invoked'] ?? null) !== false
            || ($prepared['external_execution_performed'] ?? null) !== false) {
            return self::error('execution_state_conflict', 'Prepared handoff must precede any adapter or external execution.');
        }
        $authorizationHash = strtolower(trim((string)($permit['authorization_hash'] ?? '')));
        $evidenceHash = strtolower(trim((string)($permit['evidence_hash'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $authorizationHash)
            || !preg_match('/^[a-f0-9]{64}$/', $evidenceHash)) {
            return self::error('invalid_binding', 'Permit authorization and evidence bindings are required.');
        }

        return [
            'state' => 'ETSY_ADAPTER_HANDOFF_VALIDATED',
            'operation_id' => $operationId,
            'authorization_hash' => $authorizationHash,
            'evidence_hash' => $evidenceHash,
            'permit' => $permit,
            'payload' => (array)($prepared['payload'] ?? []),
            'ledger_state_change_permitted' => false,
            'sent_state_claimed' => false,
            'adapter_invoked' => false,
            'external_execution_performed' => false,
        ];
    }

    private static function error(string $code, string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_handoff_'.$code, $message, ['status' => 409]);
    }
}
