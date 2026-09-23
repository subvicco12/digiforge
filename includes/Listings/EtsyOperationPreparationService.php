<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use DigiForge\POD\ExecutionOrchestrator;
use WP_Error;

/**
 * Local preparation boundary for a future Etsy adapter invocation.
 *
 * It binds an approved Etsy operation ledger record to a consumed controlled
 * execution permit. It deliberately does not invoke an adapter or contact Etsy.
 */
final class EtsyOperationPreparationService
{
    public function __construct(private EtsyOperationRepository $operations)
    {
    }

    /** @return array<string,mixed>|WP_Error */
    public function prepare(
        int $operationId,
        array $authorization,
        string $evidenceHash,
        int $actor,
        int $now,
        array $payload
    ): array|WP_Error {
        $operation = $this->operations->find($operationId);
        if (!is_array($operation)) {
            return $this->error('operation_not_found', 'Etsy operation record not found.', 404);
        }
        if ((string)($operation['state'] ?? '') !== EtsyOperationLifecycle::NOT_SENT) {
            return $this->error('operation_not_sendable', 'Only a NOT_SENT Etsy operation may be prepared.', 409);
        }

        $operationType = strtoupper(trim((string)($operation['operation_type'] ?? '')));
        $humanApproved = (int)($operation['intent_id'] ?? 0) > 0 && (int)($operation['draft_package_id'] ?? 0) > 0;
        $policy = EtsyExecutionPolicy::evaluate($operationType, $humanApproved);
        if (($policy['allowed'] ?? false) !== true) {
            return $this->error('policy_denied', 'Etsy execution policy denied operation preparation: '.(string)($policy['reason'] ?? 'denied').'.', 403);
        }

        $evidenceHash = strtolower(trim($evidenceHash));
        if (!hash_equals((string)($operation['evidence_hash'] ?? ''), $evidenceHash)) {
            return $this->error('evidence_mismatch', 'Execution evidence does not match the persisted Etsy operation.', 409);
        }

        $authorizationHash = strtolower(trim((string)($authorization['authorization_hash'] ?? '')));
        if ($authorizationHash === '' || !hash_equals((string)($operation['authorization_hash'] ?? ''), $authorizationHash)) {
            return $this->error('authorization_mismatch', 'Execution authorization does not match the persisted Etsy operation.', 409);
        }

        $requestFingerprint = hash('sha256', wp_json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '');
        if (!hash_equals((string)($operation['request_fingerprint'] ?? ''), $requestFingerprint)) {
            return $this->error('request_mismatch', 'Execution payload does not match the persisted Etsy operation.', 409);
        }

        $prepared = ExecutionOrchestrator::prepare(
            $authorization,
            $operationType,
            $evidenceHash,
            $actor,
            $now,
            $payload
        );
        if ($prepared instanceof WP_Error) {
            return $prepared;
        }

        return [
            'state' => 'ETSY_OPERATION_PREPARED',
            'operation_id' => $operationId,
            'operation_type' => $operationType,
            'permit' => $prepared['permit'],
            'payload' => $prepared['payload'],
            'adapter_invoked' => false,
            'external_execution_performed' => false,
        ];
    }

    private function error(string $code, string $message, int $status): WP_Error
    {
        return new WP_Error('digiforge_etsy_preparation_'.$code, $message, ['status' => $status]);
    }
}
