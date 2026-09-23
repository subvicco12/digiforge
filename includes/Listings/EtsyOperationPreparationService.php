<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use DigiForge\Database\Tables;
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
        $mapping = $this->operationMapping($operationType);
        if ($mapping instanceof WP_Error) return $mapping;

        $scope = $this->currentApprovedScope($operation);
        if ($scope instanceof WP_Error) return $scope;

        $policy = EtsyExecutionPolicy::evaluate($mapping['policy_operation'], true);
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

        $requestFingerprint = EtsyRequestFingerprint::fromPayload($payload);
        if ($requestFingerprint instanceof WP_Error) return $requestFingerprint;
        $persistedFingerprint = (string)($operation['request_fingerprint'] ?? '');
        if (!hash_equals($persistedFingerprint, $requestFingerprint)) {
            // Compatibility for NOT_SENT records persisted before canonical
            // fingerprinting was introduced. New records should use the
            // canonical fingerprint contract.
            $legacyJson = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
            $legacyFingerprint = is_string($legacyJson) ? hash('sha256', $legacyJson) : '';
            if ($legacyFingerprint === '' || !hash_equals($persistedFingerprint, $legacyFingerprint)) {
                return $this->error('request_mismatch', 'Execution payload does not match the persisted Etsy operation.', 409);
            }
        }

        $prepared = ExecutionOrchestrator::prepare(
            $authorization,
            $mapping['authorization_action'],
            $evidenceHash,
            $actor,
            $now,
            $payload
        );
        if ($prepared instanceof WP_Error) return $prepared;

        return [
            'state' => 'ETSY_OPERATION_PREPARED',
            'operation_id' => $operationId,
            'operation_type' => $operationType,
            'policy_operation' => $mapping['policy_operation'],
            'authorization_action' => $mapping['authorization_action'],
            'permit' => $prepared['permit'],
            'payload' => $prepared['payload'],
            'adapter_invoked' => false,
            'external_execution_performed' => false,
        ];
    }

    /** @return array{policy_operation:string,authorization_action:string}|WP_Error */
    private function operationMapping(string $operationType): array|WP_Error
    {
        return match ($operationType) {
            'DRAFT', 'CREATE_DRAFT' => [
                'policy_operation' => EtsyExecutionPolicy::OP_DRAFT,
                'authorization_action' => 'ETSY_DRAFT_CREATE',
            ],
            default => $this->error('operation_not_supported', 'Etsy operation type is not authorized for controlled preparation.', 403),
        };
    }

    /** @return array<string,mixed>|WP_Error */
    private function currentApprovedScope(array $operation): array|WP_Error
    {
        global $wpdb;
        $intentId=(int)($operation['intent_id']??0);
        $packageId=(int)($operation['draft_package_id']??0);
        $intent=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::etsy_intents().' WHERE id=%d LIMIT 1',$intentId),ARRAY_A);
        $package=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::etsy_draft_packages().' WHERE id=%d LIMIT 1',$packageId),ARRAY_A);
        if (!is_array($intent) || (string)($intent['state']??'') !== 'APPROVED_INTENT') {
            return $this->error('intent_not_approved', 'Referenced Etsy intent is no longer approved.', 409);
        }
        $intentType = strtoupper(trim((string)($intent['intent_type'] ?? '')));
        if (!$this->intentMatchesOperation($intentType, (string)($operation['operation_type'] ?? ''))) {
            return $this->error('intent_operation_mismatch', 'Approved Etsy intent type does not authorize this operation type.', 409);
        }
        if (!is_array($package) || (int)($package['approved_by']??0) < 1 || empty($package['approved_at'])) {
            return $this->error('package_not_approved', 'Referenced Etsy draft package is no longer approved.', 409);
        }
        if ((int)($intent['draft_package_id']??0) !== $packageId || (int)($intent['listing_id']??0) !== (int)($package['listing_id']??0)) {
            return $this->error('scope_mismatch', 'Current intent and draft package approval scope does not match.', 409);
        }
        $listing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::listings().' WHERE id=%d LIMIT 1',(int)$intent['listing_id']),ARRAY_A);
        if (!is_array($listing) || (string)($listing['state']??'') !== 'APPROVED' || !hash_equals((string)($listing['shop_reference']??''),(string)($operation['shop_reference']??''))) {
            return $this->error('listing_not_approved', 'Referenced listing approval or shop scope is no longer valid.', 409);
        }
        return ['intent'=>$intent,'package'=>$package,'listing'=>$listing];
    }

    private function intentMatchesOperation(string $intentType, string $operationType): bool
    {
        $operationType = strtoupper(trim($operationType));
        return match ($intentType) {
            'PREPARE_DRAFT' => in_array($operationType, ['DRAFT', 'CREATE_DRAFT'], true),
            default => false,
        };
    }

    private function error(string $code, string $message, int $status): WP_Error
    {
        return new WP_Error('digiforge_etsy_preparation_'.$code, $message, ['status' => $status]);
    }
}
