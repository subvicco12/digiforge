<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Separates operation-level retry eligibility from authorization replay.
 *
 * A consumed execution authorization is never reusable. A confirmed failure
 * may only produce a local plan for a NEW operation with a NEW authorization
 * and a NEW idempotency key. This class performs no HTTP, persistence,
 * authorization consumption, scheduling, adapter invocation, or external action.
 */
final class EtsyRetryPlan
{
    /** @return array<string,mixed>|WP_Error */
    public static function build(array $operation, string $newIdempotencyKey): array|WP_Error
    {
        $rawId = $operation['id'] ?? null;
        if (is_int($rawId)) {
            $id = $rawId;
        } elseif (is_string($rawId) && preg_match('/^[1-9][0-9]*$/', $rawId)) {
            $id = filter_var($rawId, FILTER_VALIDATE_INT);
        } else {
            $id = false;
        }
        if ($id === false || $id < 1) {
            return self::error('operation_id', 'Retry planning requires a persisted Etsy operation id.');
        }

        if (($operation['state'] ?? null) !== EtsyOperationLifecycle::CONFIRMED_FAILURE) {
            return self::error('state', 'Only CONFIRMED_FAILURE Etsy operations may produce a retry plan.');
        }

        $oldKey = $operation['idempotency_key'] ?? null;
        $authorizationHash = $operation['authorization_hash'] ?? null;
        if (!is_string($oldKey) || !is_string($authorizationHash)) {
            return self::error('binding', 'Retry planning requires persisted idempotency and authorization evidence.');
        }
        $oldKey = trim($oldKey);
        $authorizationHash = strtolower(trim($authorizationHash));
        $newIdempotencyKey = trim($newIdempotencyKey);
        if (
            $oldKey === '' || strlen($oldKey) > 191 ||
            $newIdempotencyKey === '' || strlen($newIdempotencyKey) > 191 ||
            hash_equals($oldKey, $newIdempotencyKey) ||
            !preg_match('/^[a-f0-9]{64}$/', $authorizationHash)
        ) {
            return self::error('binding', 'Retry requires a new idempotency key and valid prior authorization evidence.');
        }

        return [
            'source_operation_id' => (int)$id,
            'source_state' => EtsyOperationLifecycle::CONFIRMED_FAILURE,
            'new_operation_required' => true,
            'new_authorization_required' => true,
            'new_idempotency_key' => $newIdempotencyKey,
            'prior_authorization_hash' => $authorizationHash,
            'reuse_prior_authorization' => false,
            'reuse_prior_idempotency_key' => false,
            'adapter_invoked' => false,
            'external_execution_performed' => false,
        ];
    }

    private static function error(string $code, string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_retry_'.$code, $message, ['status' => 409]);
    }
}
